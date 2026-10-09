"""Monthly process: IPAS session, start / continue, retries, manual uploads, restart, opening balances."""
from collections import Counter
from decimal import Decimal, InvalidOperation

from fastapi import APIRouter, Depends, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import RedirectResponse
from sqlalchemy import select

from .. import capital_schedule, config, ipas_session, jobs, ledger, periods
from ..ipas import normalize_cookie
from ..ingest import get_or_create_month, ingest_report, mark_allocation
from ..models import BalanceSubAllocation, Co6Item, Job, Month, MonthAllocation, OpeningBalance, StoredFile
from ..storage import log_event
from ..suspense import ReportError
from .auth import PROCESS_RUN, check_csrf, get_db, require
from .common import page, require_ym

router = APIRouter()
need_process = require(PROCESS_RUN)


def _month(db, ym):
    return db.scalars(select(Month).where(Month.ym == ym)).first()


def status_context(db, ym, user):
    month = _month(db, ym)
    context = {"ym": ym, "month": month, "period": periods.period(ym), "ipas": ipas_session.info(user.id),
               "allocations": [], "job": None, "jvs": [], "sheets": [], "counts": {}, "files": {}, "openings": [],
               "verification": None, "cs_error": None}
    if month is None:
        context["allocations"] = [{"allocation": a, "status": "pending"} for a in config.ALLOCATIONS]
        return context
    context["allocations"] = db.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id).order_by(MonthAllocation.position)).all()
    context["job"] = db.scalars(select(Job).where(Job.month_id == month.id).order_by(Job.id.desc())).first()
    items = db.scalars(select(Co6Item).where(Co6Item.month_id == month.id).order_by(Co6Item.kind, Co6Item.position)).all()
    context["jvs"] = [i for i in items if i.kind == "jv"]
    context["sheets"] = [i for i in items if i.kind == "sheet"]
    context["counts"] = {k: Counter(i.status for i in items if i.kind == k) for k in ("jv", "sheet")}
    context["files"] = {f.kind: f for f in db.scalars(select(StoredFile).where(
        StoredFile.month_id == month.id, StoredFile.kind.in_(("jv_reports_txt", "jv_reports_pdf", "allocation_sheets_pdf",
                                                             "vouchers_pdf", "daybook_book_pdf"))))}
    if month.capital_file_id and month.reports_ready_at:
        context["verification"], context["cs_error"] = capital_schedule.try_verify(db, month)
    balances = db.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.ym == ym).order_by(BalanceSubAllocation.code)).all()
    manual = {o.code: o for o in db.scalars(select(OpeningBalance).where(OpeningBalance.ym == ym))}
    if any(b.opening_source in ("missing", "manual") for b in balances):
        context["openings"] = [{"code": b.code, "label": "%s-%s" % (b.code[:2], b.code[2:]), "balance": b, "manual": manual.get(b.code)}
                               for b in balances]
    return context


def _status(request, db, ym, user, message=None, error=None):
    return page(request, "_process_status.html", message=message, error=error, **status_context(db, ym, user))


@router.get("/process")
def process_home(month: str = None, user=Depends(need_process)):
    ym = month if periods.is_ym(month) else periods.default_month()
    return RedirectResponse("/process/%s" % ym, status_code=303)


@router.get("/process/{ym}")
def process_page(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    require_ym(ym)
    return page(request, "process.html", **status_context(db, ym, user))


@router.get("/process/{ym}/status")
def process_status(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    return _status(request, db, require_ym(ym), user)


@router.post("/process/{ym}/start", dependencies=[Depends(check_csrf)])
def process_start(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    require_ym(ym)
    job = jobs.start(db, ym, user.id)
    return _status(request, db, ym, user, message="Processing started." if job.status == "queued" else "Already running.")


@router.post("/process/{ym}/retry", dependencies=[Depends(check_csrf)])
def process_retry(request: Request, ym: str, kind: str = Form(...), ref: str = Form(...), user=Depends(need_process), db=Depends(get_db)):
    month = _month(db, require_ym(ym))
    if month is None:
        raise HTTPException(404, "Nothing to retry.")
    if kind == "report":
        mark_allocation(db, month, ref, "pending")
    elif kind == "capital":
        month.capital_status, month.capital_message, month.capital_file_id = "pending", None, None
        month.completed_at = None
    else:
        item = db.scalars(select(Co6Item).where(Co6Item.month_id == month.id, Co6Item.kind == kind, Co6Item.number == ref)).first()
        if item:
            item.status, item.message = "pending", None
            month.documents_ready_at = None
            month.completed_at = None
    db.commit()
    jobs.start(db, ym, user.id)
    return _status(request, db, ym, user, message="Retrying %s." % ref)


@router.post("/process/{ym}/upload/{allocation}", dependencies=[Depends(check_csrf)])
async def process_upload(request: Request, ym: str, allocation: str, file: UploadFile = File(...), user=Depends(need_process), db=Depends(get_db)):
    require_ym(ym)
    if allocation not in config.ALLOCATIONS:
        raise HTTPException(404, "Unknown allocation.")
    month = get_or_create_month(db, ym)
    if jobs.active_job(db, month.id):
        return _status(request, db, ym, user, error="Wait for the running process to finish before uploading.")
    content = await file.read()
    try:
        ingest_report(db, month, allocation, content, file.filename or "upload", "upload", user.id)
    except ReportError as e:
        mark_allocation(db, month, allocation, "failed", str(e))
        db.commit()
        return _status(request, db, ym, user, error="Allocation %s: %s" % (allocation, e))
    db.commit()
    return _status(request, db, ym, user, message="Allocation %s loaded from %s. Click Continue to carry on." % (allocation, file.filename))


@router.post("/process/{ym}/reset", dependencies=[Depends(check_csrf)])
def process_reset(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    """Restart, fetching everything from IPAS again."""
    try:
        jobs.reset_month(db, require_ym(ym), user.id)
    except RuntimeError as e:
        return _status(request, db, ym, user, error=str(e))
    db.commit()
    if not ipas_session.info(user.id)["set"]:
        return _status(request, db, ym, user, message="%s restarted. Paste the IPAS session, then click Start." % periods.label(ym))
    jobs.start(db, ym, user.id)
    return _status(request, db, ym, user, message="%s restarted: downloading everything from IPAS again." % periods.label(ym))


@router.post("/process/{ym}/restart-from-downloads", dependencies=[Depends(check_csrf)])
def process_restart_from_downloads(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    """Restart from the reports already downloaded, without fetching them from IPAS again."""
    try:
        reused, missing = jobs.restart_from_downloads(db, require_ym(ym), user.id)
    except RuntimeError as e:
        return _status(request, db, ym, user, error=str(e))
    db.commit()
    jobs.start(db, ym, user.id)
    note = "" if not missing else " Allocation %s still to be downloaded from IPAS." % ", ".join(missing)
    return _status(request, db, ym, user, message="%s restarted from %d downloaded reports; JV reports and allocation sheets already downloaded are reused.%s" % (periods.label(ym), reused, note))


@router.post("/process/{ym}/openings", dependencies=[Depends(check_csrf)])
async def process_openings(request: Request, ym: str, user=Depends(need_process), db=Depends(get_db)):
    require_ym(ym)
    form = await request.form()
    saved = 0
    for key, value in form.items():
        if not key.startswith("last_"):
            continue
        code = key[5:]
        text = str(value).replace(",", "").strip()
        if text == "":
            continue
        try:
            last = Decimal(text)
            running_text = str(form.get("running_" + code, "")).replace(",", "").strip()
            running = Decimal(running_text) if running_text else last
        except InvalidOperation:
            return _status(request, db, ym, user, error="Opening for %s-%s is not a number." % (code[:2], code[2:]))
        opening = db.scalars(select(OpeningBalance).where(OpeningBalance.ym == ym, OpeningBalance.code == code)).first()
        if opening is None:
            opening = OpeningBalance(ym=ym, code=code)
            db.add(opening)
        opening.last_month_fy = last
        opening.opening_running = running
        opening.running_confirmed = bool(running_text)
        opening.entered_by = user.id
        saved += 1
    db.flush()
    log_event(db, "openings_saved", ym, message="%d sub-allocations" % saved, user_id=user.id)
    ledger.recompute_from(db, ym)
    db.commit()
    rebuilding = jobs.rebuild_outputs(db, ym, user.id)
    return _status(request, db, ym, user, message="Opening balances saved; balances recalculated." +
                   (" The month PDFs are being rebuilt." if rebuilding else ""))


@router.post("/process/{ym}/capital-schedule", dependencies=[Depends(check_csrf)])
async def process_capital_upload(request: Request, ym: str, file: UploadFile = File(...), user=Depends(need_process), db=Depends(get_db)):
    """Use a Capital Schedule (.html) downloaded from IPAS by hand."""
    require_ym(ym)
    month = get_or_create_month(db, ym)
    if jobs.active_job(db, month.id):
        return _status(request, db, ym, user, error="Wait for the running process to finish before uploading.")
    try:
        capital_schedule.accept(db, month, await file.read(), file.filename or "CapitalSchedule.html", "upload", user.id)
    except capital_schedule.CapitalScheduleError as e:
        month.capital_status, month.capital_message = "failed", str(e)
        db.commit()
        return _status(request, db, ym, user, error="Capital Schedule: %s" % e)
    db.commit()
    rebuilding = jobs.rebuild_outputs(db, ym, user.id)
    return _status(request, db, ym, user, message="Capital Schedule loaded from %s.%s" % (
        file.filename, " The month PDFs are being rebuilt." if rebuilding else " Click Continue to carry on."))


@router.post("/ipas-session", dependencies=[Depends(check_csrf)])
def save_ipas_session(request: Request, cookie: str = Form(""), ym: str = Form(...), user=Depends(need_process), db=Depends(get_db)):
    try:
        ipas_session.save(user.id, normalize_cookie(cookie), request.headers.get("user-agent", ""))
    except ValueError as e:
        return _status(request, db, require_ym(ym), user, error=str(e))
    log_event(db, "ipas_session_set", user_id=user.id)
    return _status(request, db, require_ym(ym), user, message="IPAS session saved for %d hours." % (config.IPAS_COOKIE_TTL_SECONDS // 3600))


@router.post("/ipas-session/clear", dependencies=[Depends(check_csrf)])
def clear_ipas_session(request: Request, ym: str = Form(...), user=Depends(need_process), db=Depends(get_db)):
    ipas_session.clear(user.id)
    return _status(request, db, require_ym(ym), user, message="IPAS session cleared.")
