"""The monthly process, run by the background worker (RQ).

Stages, each re-entrant (a rerun only does what is not done yet):
  1. reports    download the nine Suspense Head reports; stop unless all nine are in
  2. separate   JV separation -> JV reports and allocation sheets to fetch; balances
  3. documents  download every JV report and allocation sheet
  4. outputs    merged JV text + PDF, 2-up allocation-sheet PDF; month complete
An expired AIMS session pauses the job until a fresh cookie is pasted; other failures are
recorded per item, retried on the next run, and keep the month from completing.
"""
import time
import traceback
from datetime import datetime

from redis import Redis
from rq import Queue
from sqlalchemy import delete, select

from . import aims_session, config, jv, ledger, periods
from .aims import AimsClient, AimsError, AimsSessionError
from .db import session_scope
from .ingest import get_or_create_month, ingest_report, mark_allocation
from .models import Co6Item, Job, Month, MonthAllocation, StoredFile
from .outputs import jv_pdf, sheets_pdf
from .storage import log_event, store_file
from .suspense import ReportError

ACTIVE = ("queued", "running")


class Paused(Exception):
    pass


def queue():
    return Queue(config.RQ_QUEUE, connection=Redis.from_url(config.REDIS_URL))


def _rq_id(job_id):
    return "daybook-job-%d" % job_id


def _rq_alive(job_id):
    from rq.job import Job as RqJob
    from rq.exceptions import NoSuchJobError

    try:
        status = RqJob.fetch(_rq_id(job_id), connection=Redis.from_url(config.REDIS_URL)).get_status()
    except NoSuchJobError:
        return False
    return status in ("queued", "started", "deferred", "scheduled")


def active_job(session, month_id):
    """The month's queued/running job, if its background job still exists (a worker restart can kill it)."""
    job = session.scalars(select(Job).where(Job.month_id == month_id, Job.status.in_(ACTIVE))
                          .order_by(Job.id.desc())).first()
    if job is not None and not _rq_alive(job.id):
        job.status, job.finished_at = "failed", datetime.utcnow()
        job.message = "The previous run was interrupted (the worker restarted). Click Continue to carry on."
        session.flush()
        return None
    return job


def start(session, ym, user_id):
    """Queue a run for the month unless one is already active. Returns the Job."""
    month = get_or_create_month(session, ym)
    job = active_job(session, month.id)
    if job:
        return job
    job = Job(month_id=month.id, status="queued", user_id=user_id)
    session.add(job)
    session.flush()
    log_event(session, "process_started", ym, user_id=user_id)
    session.commit()
    queue().enqueue("daybook.jobs.run_job", job.id, job_id=_rq_id(job.id), job_timeout=6 * 3600, result_ttl=3600, failure_ttl=86400)
    return job


def _set_job(job_id, **fields):
    with session_scope() as s:
        job = s.get(Job, job_id)
        for k, v in fields.items():
            setattr(job, k, v)


def run_job(job_id):
    with session_scope() as s:
        job = s.get(Job, job_id)
        job.status, job.started_at, job.stage = "running", datetime.utcnow(), "reports"
        month_id, user_id = job.month_id, job.user_id
        ym = s.get(Month, month_id).ym
    session_data = aims_session.load(user_id)
    client = None
    try:
        client = AimsClient(session_data["cookie"], session_data.get("user_agent")) if session_data else None
        if not _stage_reports(job_id, month_id, ym, client):
            return
        _set_job(job_id, stage="separate")
        _stage_separate(month_id, ym)
        _set_job(job_id, stage="documents")
        if not _stage_documents(job_id, month_id, ym, client):
            return
        _set_job(job_id, stage="outputs")
        _stage_outputs(month_id, ym)
        _set_job(job_id, status="done", stage="complete", finished_at=datetime.utcnow(),
                 message="%s is complete." % periods.label(ym))
    except Paused as e:
        _set_job(job_id, status="paused", message=str(e), finished_at=datetime.utcnow())
    except Exception as e:
        traceback.print_exc()
        _set_job(job_id, status="failed", message="Unexpected error: %s" % e, finished_at=datetime.utcnow())
        with session_scope() as s:
            s.get(Month, month_id).last_error = str(e)
    finally:
        if client:
            client.close()


def _require_client(client):
    if client is None:
        raise Paused("No AIMS session is set. Paste your AIMS session cookie, then continue.")
    return client


def _stage_reports(job_id, month_id, ym, client):
    with session_scope() as s:
        todo = [a.allocation for a in s.scalars(select(MonthAllocation).where(
            MonthAllocation.month_id == month_id, MonthAllocation.status != "downloaded").order_by(MonthAllocation.position))]
    for allocation in todo:
        with session_scope() as s:
            mark_allocation(s, s.get(Month, month_id), allocation, "downloading")
        try:
            name, content = _require_client(client).suspense_head(allocation, ym)
            with session_scope() as s:
                ingest_report(s, s.get(Month, month_id), allocation, content, name, "aims")
        except (AimsSessionError, Paused) as e:
            with session_scope() as s:
                mark_allocation(s, s.get(Month, month_id), allocation, "failed", str(e))
            raise Paused(str(e))
        except (AimsError, ReportError) as e:
            with session_scope() as s:
                mark_allocation(s, s.get(Month, month_id), allocation, "failed", str(e))
                log_event(s, "report_failed", ym, allocation, str(e))
        time.sleep(config.AIMS_THROTTLE_SECONDS)

    with session_scope() as s:
        items = s.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month_id)).all()
        missing = [a.allocation for a in sorted(items, key=lambda a: a.position) if a.status != "downloaded"]
        month = s.get(Month, month_id)
        if missing:
            job = s.get(Job, job_id)
            job.status, job.finished_at = "failed", datetime.utcnow()
            job.message = "%d / %d Suspense Head reports ready. Not continuing until allocation %s %s in - retry, or upload by hand." % (
                len(items) - len(missing), len(items), ", ".join(missing), "are" if len(missing) > 1 else "is")
            return False
        if month.reports_ready_at is None:
            month.reports_ready_at = datetime.utcnow()
    return True


def _stage_separate(month_id, ym):
    with session_scope() as s:
        month = s.get(Month, month_id)
        if month.separated_at is not None:
            return
        items = s.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month_id).order_by(MonthAllocation.position)).all()
        jv_numbers, sheet_numbers = jv.separate([item.rows for item in items])
        existing = {(c.kind, c.number): c for c in s.scalars(select(Co6Item).where(Co6Item.month_id == month_id))}
        wanted = {}
        for kind, numbers in (("jv", jv_numbers), ("sheet", sheet_numbers)):
            for position, number in enumerate(numbers, start=1):
                wanted[(kind, number)] = position
        for key, item in existing.items():
            if key not in wanted:
                s.delete(item)
        for (kind, number), position in wanted.items():
            item = existing.get((kind, number))
            if item is None:
                s.add(Co6Item(month_id=month_id, kind=kind, number=number, position=position, status="pending"))
            else:
                item.position = position
        month.separated_at = datetime.utcnow()
        month.documents_ready_at = None
        month.completed_at = None
        log_event(s, "separated", ym, message="%d JV, %d allocation sheets" % (len(jv_numbers), len(sheet_numbers)))
        s.flush()
        ledger.recompute_from(s, ym)


def _stage_documents(job_id, month_id, ym, client):
    with session_scope() as s:
        todo = [(c.id, c.kind, c.number) for c in s.scalars(select(Co6Item).where(
            Co6Item.month_id == month_id, Co6Item.status != "downloaded").order_by(Co6Item.kind, Co6Item.position))]
    for item_id, kind, number in todo:
        with session_scope() as s:
            item = s.get(Co6Item, item_id)
            item.status, item.message = "downloading", None
        try:
            api = _require_client(client)
            if kind == "jv":
                _, content = api.jv_report(number, ym)
                pages = None
                file_kind, file_name = "jv_report", "%s.txt" % number
            else:
                content = api.allocation_sheet(number)
                pages = sheets_pdf.page_count(content)
                file_kind, file_name = "allocation_sheet", "%s.pdf" % number
            with session_scope() as s:
                stored = store_file(s, month_id, file_kind, number, file_name, content)
                item = s.get(Co6Item, item_id)
                item.status, item.file_id, item.pages, item.message = "downloaded", stored.id, pages, None
        except (AimsSessionError, Paused) as e:
            with session_scope() as s:
                item = s.get(Co6Item, item_id)
                item.status, item.message = "failed", str(e)
            raise Paused(str(e))
        except Exception as e:  # AimsError, unreadable PDF
            with session_scope() as s:
                item = s.get(Co6Item, item_id)
                item.status, item.message = "failed", str(e) if isinstance(e, AimsError) else "Not a readable PDF (%s)." % e
        time.sleep(config.AIMS_THROTTLE_SECONDS)

    with session_scope() as s:
        failed = s.scalars(select(Co6Item).where(Co6Item.month_id == month_id, Co6Item.status != "downloaded")).all()
        if failed:
            job = s.get(Job, job_id)
            job.status, job.finished_at = "failed", datetime.utcnow()
            job.message = "%d JV report(s) / allocation sheet(s) could not be downloaded - see the list, then retry." % len(failed)
            return False
        month = s.get(Month, month_id)
        if month.documents_ready_at is None:
            month.documents_ready_at = datetime.utcnow()
    return True


def build_merged_documents(session, month):
    """Merged JV text + PDF and the 2-up allocation-sheet PDF, stored as files. Returns warnings."""
    items = session.scalars(select(Co6Item).where(Co6Item.month_id == month.id).order_by(Co6Item.position)).all()
    files = {f.id: f for f in session.scalars(select(StoredFile).where(StoredFile.id.in_([i.file_id for i in items if i.file_id])))}
    jvs = [(i.number, files[i.file_id].content) for i in items if i.kind == "jv"]
    sheets = [(i.number, files[i.file_id].content) for i in items if i.kind == "sheet"]
    warnings = []
    text = jv_pdf.merged_text(jvs) if jvs else ""
    store_file(session, month.id, "jv_reports_txt", None, "JV_Reports_%s.txt" % month.ym, text.encode("utf-8"))
    pdf, pdf_warnings = jv_pdf.text_pdf(text, "JV Reports %s" % periods.label(month.ym))
    warnings += pdf_warnings
    store_file(session, month.id, "jv_reports_pdf", None, "JV_Reports_%s.pdf" % month.ym, pdf)
    if sheets:
        store_file(session, month.id, "allocation_sheets_pdf", None, "Allocation_Sheets_%s_2up.pdf" % month.ym, sheets_pdf.two_up(sheets))
    return warnings


def _stage_outputs(month_id, ym):
    try:
        with session_scope() as s:
            warnings = build_merged_documents(s, s.get(Month, month_id))
    except sheets_pdf.SheetPdfError as e:
        with session_scope() as s:
            item = s.scalars(select(Co6Item).where(Co6Item.month_id == month_id, Co6Item.kind == "sheet", Co6Item.number == e.number)).first()
            if item:
                item.status, item.message = "failed", str(e)
            s.get(Month, month_id).documents_ready_at = None
        raise Paused("%s Download it again (Retry), then continue." % e)
    with session_scope() as s:
        month = s.get(Month, month_id)
        ledger.recompute_from(s, ym)
        month.completed_at = datetime.utcnow()
        month.last_error = "; ".join(warnings) or None
        log_event(s, "completed", ym)


MERGED_KINDS = ("jv_reports_txt", "jv_reports_pdf", "allocation_sheets_pdf")


def restart_from_downloads(session, ym, user_id=None):
    """Restart a month from the files already downloaded, without fetching them from AIMS again.

    The stored Suspense Head reports are read and checked again, then JV separation, outputs and
    balances are redone. JV reports and allocation sheets already downloaded are reused; only
    anything missing is fetched. Returns (reports reused, allocations still to fetch)."""
    month = session.scalars(select(Month).where(Month.ym == ym)).first()
    if month is None:
        raise RuntimeError("Nothing has been downloaded for %s yet." % periods.label(ym))
    if active_job(session, month.id):
        raise RuntimeError("The month is being processed; wait for it to finish first.")
    reused, missing = 0, []
    items = session.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id).order_by(MonthAllocation.position)).all()
    for item in items:
        original = session.get(StoredFile, item.original_file_id) if item.original_file_id else None
        if original is None:
            mark_allocation(session, month, item.allocation, "pending")
            missing.append(item.allocation)
            continue
        content, name, via = original.content, item.source_name or "SuspenseHead.xls", item.via or "aims"
        try:
            ingest_report(session, month, item.allocation, content, name, via, user_id)
            reused += 1
        except ReportError as e:
            mark_allocation(session, month, item.allocation, "failed", str(e))
            missing.append(item.allocation)
    session.execute(delete(StoredFile).where(StoredFile.month_id == month.id, StoredFile.kind.in_(MERGED_KINDS)))
    month.last_error = None
    log_event(session, "restarted_from_downloads", ym, message="%d reports reused" % reused, user_id=user_id)
    return reused, missing


def reset_month(session, ym, user_id=None):
    """Restart a month: remove its reports, documents, outputs and balances (opening balances typed in are kept)."""
    month = session.scalars(select(Month).where(Month.ym == ym)).first()
    if month is None:
        return
    if active_job(session, month.id):
        raise RuntimeError("The month is being processed; wait for it to finish or pause first.")
    session.execute(delete(Month).where(Month.id == month.id))
    ledger.remove_month(session, ym)
    log_event(session, "restarted", ym, user_id=user_id)
