"""Read-only views and exports: dashboard, month, Daybook, yearly, allocation, sub-allocation, UWID, entries."""
from fastapi import APIRouter, Depends, HTTPException, Request
from sqlalchemy import select

from .. import capital_schedule, config, periods, reports
from ..labels import ALLOCATION_NAMES
from ..models import Co6Item, Month, MonthAllocation, StoredFile
from ..outputs import documents, month_pdfs, print_layout
from .auth import REPORTS_VIEW, UWID_VIEW, get_db, permissions_of, require, uwid_scope
from .common import XLSX, download, page, parse_fy, report_response, require_ym

router = APIRouter()
need_reports = require(REPORTS_VIEW)
need_uwid = require(UWID_VIEW, REPORTS_VIEW)


def _month(db, ym):
    month = db.scalars(select(Month).where(Month.ym == ym)).first()
    if month is None:
        raise HTTPException(404, "%s has not been processed yet." % periods.label(ym))
    return month


@router.get("/")
def dashboard(request: Request, fy: str = None, user=Depends(need_reports), db=Depends(get_db)):
    fy = parse_fy(fy, periods.fy_start_year(periods.default_month()))
    months = [(ym, m, reports.month_status(m)) for ym, m in reports.months_of_fy(db, fy)]
    return page(request, "dashboard.html", fy=fy, fys=reports.available_fys(db), months=months,
                table=reports.yearly_summary(db, fy))


@router.get("/yearly")
def yearly(request: Request, fy: str = None, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    fy = parse_fy(fy, periods.fy_start_year(periods.default_month()))
    return report_response(request, "Yearly summary %s" % periods.fy_label(fy), [reports.yearly_summary(db, fy)], format,
                           fy=fy, fys=reports.available_fys(db), fy_picker=True)


@router.get("/allocations/{allocation}")
def allocation_fy(request: Request, allocation: str, fy: str = None, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    fy = parse_fy(fy, periods.fy_start_year(periods.default_month()))
    return report_response(request, "Allocation %s - %s" % (allocation, periods.fy_label(fy)),
                           [reports.yearly_summary(db, fy, allocation)], format, fy=fy, fys=reports.available_fys(db), fy_picker=True)


@router.get("/sub-allocations/{code}")
def sub_allocation(request: Request, code: str, fy: str = None, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    code = code.replace("-", "")
    fy = parse_fy(fy, periods.fy_start_year(periods.default_month()))
    return report_response(request, "Sub-allocation %s - %s" % (reports.code_label(code), periods.fy_label(fy)),
                           reports.sub_allocation(db, code, fy), format, fy=fy, fys=reports.available_fys(db), fy_picker=True)


@router.get("/months/{ym}")
def month_page(request: Request, ym: str, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    month = _month(db, require_ym(ym))
    table = reports.month_allocations(db, ym)
    if format:
        return report_response(request, "Daybook %s" % periods.label(ym), [table], format)
    allocations = db.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id).order_by(MonthAllocation.position)).all()
    files = {f.kind: f for f in db.scalars(select(StoredFile).where(
        StoredFile.month_id == month.id, StoredFile.kind.in_(("jv_reports_txt", "jv_reports_pdf", "allocation_sheets_pdf",
                                                             "vouchers_pdf", "daybook_book_pdf", "capital_schedule"))))}
    items = db.scalars(select(Co6Item).where(Co6Item.month_id == month.id).order_by(Co6Item.kind, Co6Item.position)).all()
    verification, cs_error = capital_schedule.try_verify(db, month)
    checks = {}
    if verification:
        balances = reports.month_allocation_codes(db, ym)
        checks = {a: verification.allocation_counts(a, balances.get(a, [])) for a in config.ALLOCATIONS}
    return page(request, "month.html", ym=ym, month=month, table=table, allocations=allocations, files=files, items=items,
                status=reports.month_status(month), verification=verification, cs_error=cs_error, checks=checks,
                names=ALLOCATION_NAMES)


@router.get("/months/{ym}/capital-schedule")
def capital_schedule_page(request: Request, ym: str, user=Depends(need_reports), db=Depends(get_db)):
    month = _month(db, require_ym(ym))
    verification, error = capital_schedule.try_verify(db, month)
    if verification is None:
        raise HTTPException(404, error or "The Capital Schedule for %s has not been downloaded yet." % periods.label(ym))
    context = month_pdfs.book_context(db, month)
    return page(request, "capital_schedule.html", month=month, **context)


@router.get("/months/{ym}/allocations/{allocation}")
def daybook_page(request: Request, ym: str, allocation: str, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    month = _month(db, require_ym(ym))
    item, daybook = documents.daybook_for(db, month, allocation)
    if daybook is None:
        raise HTTPException(404, "Allocation %s for %s has not been downloaded yet." % (allocation, periods.label(ym)))
    name = "DayBook_%s_Allocation_%s" % (ym, allocation)
    if format == "xlsx":
        return download(documents.daybook_excel(db, month, allocation), name + ".xlsx", XLSX)
    if format == "pdf":
        return download(documents.daybook_pdf(db, month, allocation), name + ".pdf", "application/pdf")
    summary, _ = reports.allocation_summary(db, ym, allocation)
    verification, cs_error = capital_schedule.try_verify(db, month)
    checks = verification.codes if verification else None
    cs_counts = verification.allocation_counts(allocation, [r[0].replace("-", "") for r in summary.rows]) if verification else None
    return page(request, "daybook.html", ym=ym, month=month, item=item, daybook=daybook, summary=summary, allocation=allocation,
                allocations=config.ALLOCATIONS, layouts=print_layout.layout(daybook), checks=checks, cs_counts=cs_counts,
                totals_check=verification.totals.get(allocation) if verification else None,
                cs_error=cs_error, names=ALLOCATION_NAMES)


@router.get("/months/{ym}/zip")
def month_zip(ym: str, user=Depends(need_reports), db=Depends(get_db)):
    month = _month(db, require_ym(ym))
    if not month.completed_at:
        raise HTTPException(409, "%s is not complete yet; outputs are available once every report, JV report and allocation sheet is in." % periods.label(ym))
    return download(documents.month_zip(db, month), "Daybook_%s.zip" % ym, "application/zip")


@router.get("/files/{file_id}")
def stored_file(file_id: int, user=Depends(need_reports), db=Depends(get_db)):
    f = db.get(StoredFile, file_id)
    if f is None:
        raise HTTPException(404, "File not found.")
    return download(f.content, f.file_name, f.mime_type)


# ---- UWID (also for the UWID Viewer role) ---------------------------------------------

def _limit_links(user, tables):
    if REPORTS_VIEW not in permissions_of(user):
        for t in tables:
            t.links = {k: v for k, v in t.links.items() if v.startswith("/uwids")}
    return tables


@router.get("/uwids")
def uwid_search(request: Request, q: str = "", format: str = None, user=Depends(need_uwid), db=Depends(get_db)):
    table = reports.uwid_search(db, q, uwid_scope(user))
    return report_response(request, "UWIDs", _limit_links(user, [table]), format, template="uwids.html", q=q)


@router.get("/uwids/{uwid}")
def uwid_detail(request: Request, uwid: str, fy: str = None, format: str = None, user=Depends(need_uwid), db=Depends(get_db)):
    year = None if fy in (None, "", "all") else parse_fy(fy, None)
    tables, found = reports.uwid_detail(db, uwid, year, uwid_scope(user))
    if not found:
        raise HTTPException(404, "No bookings found for UWID %s%s." % (uwid, "" if year is None else " in " + periods.fy_label(year)))
    title = "UWID %s - %s" % (uwid, "all months" if year is None else periods.fy_label(year))
    return report_response(request, title, _limit_links(user, tables), format, fy=year, fys=reports.available_fys(db),
                           fy_picker=True, fy_all=True)


@router.get("/entries")
def entries(request: Request, format: str = None, user=Depends(need_reports), db=Depends(get_db)):
    filters = {k: v for k, v in request.query_params.items() if k != "format" and v}
    table = reports.entry_search(db, filters) if filters else None
    if format and table:
        return report_response(request, "Entries", [table], format)
    return page(request, "entries.html", filters=filters, table=table, allocations=config.ALLOCATIONS)
