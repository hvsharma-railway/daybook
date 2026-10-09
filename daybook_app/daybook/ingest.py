"""Taking in a Suspense Head report: validate, store the files, record every entry."""
from decimal import Decimal, InvalidOperation

from sqlalchemy import delete, select

from . import config, periods
from . import phpcompat as php
from .models import Month, MonthAllocation, SuspenseEntry
from .labels import rename_heads
from .storage import log_event, store_file
from .suspense import read_report, validate, write_xlsx


def get_or_create_month(session, ym):
    month = session.scalars(select(Month).where(Month.ym == ym)).first()
    if month is None:
        month = Month(ym=ym, au=config.IPAS_AU)
        session.add(month)
        session.flush()
    existing = {a.allocation for a in month.allocations}
    for position, allocation in enumerate(config.ALLOCATIONS, start=1):
        if allocation not in existing:
            session.add(MonthAllocation(month_id=month.id, allocation=allocation, position=position, status="pending"))
    session.flush()
    session.refresh(month)
    return month


def month_allocation(session, month, allocation):
    return session.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id,
                                                          MonthAllocation.allocation == allocation)).one()


def invalidate(month):
    """A report changed: separation, documents and completion must be redone."""
    month.reports_ready_at = None
    month.separated_at = None
    month.documents_ready_at = None
    month.completed_at = None


def _amount(text):
    if text is None or not php.is_numeric_string(text):
        return None
    try:
        return Decimal(text.strip())
    except InvalidOperation:
        return None


def _include(code):
    n = php.to_number(php.substr(code, 2, 4))
    return n < 66 or n == 81 or n == 83


def _entries(rows):
    """Transaction rows under each ALLOCATION heading (same reading as view.php)."""
    blocks = []          # (code, head, [(row_no, row)])
    for index, t in enumerate(rows):
        if index == 0:
            continue
        t0 = t[0] if t else None
        if t0 is not None and "ALLOCATION " in t0:
            blocks.append((php.substr(t0, 13, 4), php.substr(t0, 13, 8), []))
        elif blocks and t0 not in (None, "", "SECTION"):
            blocks[-1][2].append((index + 1, t))
    # view.php keeps only the last block of a head that appears twice
    last_block = {(code, head): i for i, (code, head, _) in enumerate(blocks)}
    for i, (code, head, entries) in enumerate(blocks):
        for row_no, t in entries:
            cell = lambda c: t[c] if c < len(t) else None
            sys_generated = "SYS-GENERATED" in php.to_string(cell(4))
            yield {
                "code": code, "head": head, "row_no": row_no, "section": cell(0), "co6": cell(1), "co7": cell(2),
                "book_date": cell(3), "party_name": cell(4), "bill_desc": cell(5),
                "debit_text": cell(6), "credit_text": cell(7), "debit": _amount(cell(6)), "credit": _amount(cell(7)),
                "spu": cell(8), "contract_id": cell(9), "tan_number": cell(10), "uwid": cell(11),
                "is_jv": "JV" in php.to_string(cell(0)), "is_sys_generated": sys_generated,
                "in_daybook": _include(code) and not sys_generated and last_block[(code, head)] == i,
            }


_LENGTHS = {"section": 50, "co6": 50, "co7": 50, "book_date": 20, "debit_text": 40, "credit_text": 40,
            "spu": 100, "contract_id": 100, "tan_number": 50, "uwid": 50}


def ingest_report(session, month, allocation, content, source_name, via, user_id=None):
    """Validate and store one allocation's report. Raises suspense.ReportError with the reason."""
    year, mon = periods.parse_ym(month.ym)
    report = read_report(content)
    heads, entries = validate(report.rows, allocation, year, mon)
    rows = rename_heads(report.rows)   # after the check, which must see IPAS's own head codes

    item = month_allocation(session, month, allocation)
    original = store_file(session, month.id, "report_original", allocation, "%s - %s" % (allocation, source_name), content)
    converted = store_file(session, month.id, "report_converted", allocation, "%s.xlsx" % allocation, write_xlsx(rows))

    session.execute(delete(SuspenseEntry).where(SuspenseEntry.month_id == month.id, SuspenseEntry.allocation == allocation))
    for e in _entries(rows):
        session.add(SuspenseEntry(month_id=month.id, allocation=allocation, **{
            k: (v[:_LENGTHS[k]] if isinstance(v, str) and k in _LENGTHS else v) for k, v in e.items()}))

    item.status = "downloaded"
    item.message = None
    item.via = via
    item.source_name = source_name
    item.file_format = report.file_format
    item.heads = heads
    item.entries = entries
    item.original_file_id = original.id
    item.converted_file_id = converted.id
    item.rows = rows
    item.warnings = report.warnings
    invalidate(month)
    log_event(session, "report_received", month.ym, allocation, "%s via %s: %d entries" % (source_name, via, entries), user_id)
    return item


def mark_allocation(session, month, allocation, status, message=None):
    item = month_allocation(session, month, allocation)
    item.status = status
    item.message = message
    if status != "downloaded":
        invalidate(month)
    return item
