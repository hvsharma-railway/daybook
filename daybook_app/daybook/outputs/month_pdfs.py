"""The two month PDFs.

Daybook Vouchers SEP - 2026.pdf   allocation sheets (2 per landscape page), then the JV reports (60%)
Daybook SEP - 2026.pdf            contents with page numbers, the Capital Schedule with the figures
                                  that match the Daybook highlighted, then each allocation's
                                  sub-allocation tables and its summary (with the check marks)
"""
import calendar
import io

from pypdf import PdfReader, PdfWriter
from sqlalchemy import select

from .. import capital_schedule, config, periods, reports
from ..labels import ALLOCATION_NAMES
from ..models import StoredFile
from ..storage import store_file
from . import documents, print_layout


def month_label(ym):
    """SEP - 2026"""
    year, month = periods.parse_ym(ym)
    return "%s - %d" % (calendar.month_abbr[month].upper(), year)


def _file(session, month, kind):
    return session.scalars(select(StoredFile).where(StoredFile.month_id == month.id, StoredFile.kind == kind)
                           .order_by(StoredFile.id.desc())).first()


def vouchers_cover_pdf(session, month):
    from weasyprint import HTML

    from ..web.templating import render

    return HTML(string=render("vouchers_cover_print.html", label=month_label(month.ym))).write_pdf()


def vouchers_pdf(session, month):
    writer = PdfWriter()
    writer.append(PdfReader(io.BytesIO(vouchers_cover_pdf(session, month))), outline_item="Cover")
    for kind, title in (("allocation_sheets_pdf", "Allocation sheets"), ("jv_reports_pdf", "JV reports")):
        stored = _file(session, month, kind)
        if stored is not None:
            writer.append(PdfReader(io.BytesIO(stored.content)), outline_item=title)
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


def book_context(session, month):
    """Everything the Daybook book (and the Capital Schedule page) shows."""
    verification = capital_schedule.verify(session, month)
    allocations = []
    for allocation in config.ALLOCATIONS:
        item, daybook = documents.daybook_for(session, month, allocation)
        if daybook is None:
            continue
        summary, _ = reports.allocation_summary(session, month.ym, allocation)
        allocations.append({"allocation": allocation, "name": ALLOCATION_NAMES.get(allocation, ""), "daybook": daybook,
                            "summary": summary, "layouts": print_layout.layout(daybook)})
    return {"verification": verification, "allocations": allocations, "ym": month.ym, "label": month_label(month.ym),
            "period": periods.period(month.ym), "column_allocations": capital_schedule.COLUMN_ALLOCATIONS,
            "allocation_names": ALLOCATION_NAMES}


def book_pdf(session, month):
    from weasyprint import HTML

    from ..web.templating import render

    return HTML(string=render("month_book_print.html", **book_context(session, month))).write_pdf()


def build(session, month):
    """Make and store both PDFs."""
    label = month_label(month.ym)
    store_file(session, month.id, "vouchers_pdf", None, "Daybook Vouchers %s.pdf" % label, vouchers_pdf(session, month))
    store_file(session, month.id, "daybook_book_pdf", None, "Daybook %s.pdf" % label, book_pdf(session, month))
