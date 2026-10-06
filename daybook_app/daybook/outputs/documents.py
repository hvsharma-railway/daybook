"""Daybook documents for one allocation-month (Excel, PDF) and the month's ZIP of everything."""
import io
import zipfile

from sqlalchemy import select

from .. import periods, reports
from ..engine import build_daybook
from ..models import Co6Item, Month, MonthAllocation, StoredFile
from . import excel, print_layout


def daybook_for(session, month, allocation):
    item = session.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id,
                                                          MonthAllocation.allocation == allocation)).first()
    if item is None or item.status != "downloaded":
        return None, None
    return item, build_daybook(item.rows or [])


def daybook_excel(session, month, allocation):
    _, daybook = daybook_for(session, month, allocation)
    summary, _ = reports.allocation_summary(session, month.ym, allocation)
    rows = [{"label": r[0], "last": r[1], "for": r[2], "to": r[3]} for r in summary.rows]
    return excel.daybook_workbook(daybook, allocation, periods.label(month.ym), rows)


def daybook_pdf(session, month, allocation):
    from weasyprint import HTML

    from ..web.templating import render

    _, daybook = daybook_for(session, month, allocation)
    summary, _ = reports.allocation_summary(session, month.ym, allocation)
    html = render("daybook_print.html", daybook=daybook, summary=summary, allocation=allocation, ym=month.ym,
                  layouts=print_layout.layout(daybook), print_only=True)
    return HTML(string=html).write_pdf()


def report_pdf(title, tables, subtitle=""):
    from weasyprint import HTML

    from ..web.templating import render

    return HTML(string=render("report_print.html", title=title, subtitle=subtitle, tables=tables)).write_pdf()


def month_zip(session, month):
    """Everything for a completed month."""
    buffer = io.BytesIO()
    label = periods.label(month.ym)
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as z:
        items = session.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id).order_by(MonthAllocation.position)).all()
        for item in items:
            a = item.allocation
            z.writestr("Daybook Excel/DayBook_%s_Allocation_%s.xlsx" % (month.ym, a), daybook_excel(session, month, a))
            z.writestr("Daybook PDF/DayBook_%s_Allocation_%s.pdf" % (month.ym, a), daybook_pdf(session, month, a))
        files = session.scalars(select(StoredFile).where(StoredFile.month_id == month.id)).all()
        folders = {
            "report_original": "Suspense Head files (as received)",
            "report_converted": "Suspense Head files",
            "jv_report": "JV reports",
            "jv_reports_txt": "JV reports",
            "jv_reports_pdf": "JV reports",
            "allocation_sheet": "Allocation sheets",
            "allocation_sheets_pdf": "Allocation sheets",
        }
        for f in files:
            if f.kind in folders:
                z.writestr("%s/%s" % (folders[f.kind], f.file_name), f.content)
        co6 = session.scalars(select(Co6Item).where(Co6Item.month_id == month.id).order_by(Co6Item.kind, Co6Item.position)).all()
        z.writestr("JV and CO6/JV numbers.txt", "\r\n".join(c.number for c in co6 if c.kind == "jv") + "\r\n")
        z.writestr("JV and CO6/CO6 numbers for allocation sheets.txt", "\r\n".join(c.number for c in co6 if c.kind == "sheet") + "\r\n")
        z.writestr("README.txt", "\r\n".join([
            "Daybook outputs for %s (%s to %s)" % (label, periods.period(month.ym)["start_display"], periods.period(month.ym)["end_display"]),
            "",
            "Daybook Excel/   one workbook per allocation: Summary (Last / For / To The Month) + one sheet per sub-allocation",
            "Daybook PDF/     the same Daybook, ready to print",
            "Suspense Head files/  the nine reports used (converted .xlsx); '(as received)' has them exactly as downloaded",
            "JV reports/      each JV report, all of them merged (.txt) and the merged PDF (landscape, 60%)",
            "Allocation sheets/  each allocation sheet and all of them in one PDF, two per landscape page",
            "JV and CO6/      the JV and CO6 numbers from JV separation",
            ""]))
    return buffer.getvalue()
