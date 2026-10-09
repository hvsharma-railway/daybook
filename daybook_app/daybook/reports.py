"""Read-only views over the stored data: month, allocation, sub-allocation, UWID, yearly.

Each view returns ReportTables, which the web pages render and the Excel / PDF exports
reuse, so screen and exports always show the same figures.
"""
from collections import defaultdict
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Optional

from sqlalchemy import false, func, or_, select

from . import config, periods
from .labels import head_label
from .models import BalanceSubAllocation, BalanceUwid, Co6Item, Month, MonthAllocation, SuspenseEntry, UwidMaster

ZERO = Decimal("0.00")


@dataclass
class Column:
    label: str
    money: bool = False


@dataclass
class ReportTable:
    title: str
    columns: list
    rows: list = field(default_factory=list)
    total: Optional[list] = None
    links: dict = field(default_factory=dict)     # (row index, column index) -> url
    note: str = ""


def code_label(code):
    return "%s-%s" % (code[:2], code[2:4])


def basis():
    """Which balances the views show: financial year (default) or running."""
    return "running" if config.BALANCE_MODE == "running" else "fy"


def _bal(b):
    """(Last Month, For The Month, To The Month) on the active basis."""
    if basis() == "running":
        return b.opening_running, b.for_month, b.closing_running
    return b.last_month_fy, b.for_month, b.to_month_fy


def _sum(rows, index):
    return sum((r[index] or ZERO for r in rows), ZERO)


def month_status(month):
    if month is None:
        return "not started"
    if month.completed_at:
        return "complete"
    return "in progress"


# ---- dashboard / yearly ------------------------------------------------------------

def months_of_fy(session, fy):
    yms = periods.fy_months(fy)
    found = {m.ym: m for m in session.scalars(select(Month).where(Month.ym.in_(yms)))}
    return [(ym, found.get(ym)) for ym in yms]


def available_fys(session):
    years = {periods.fy_start_year(ym) for ym in session.scalars(select(Month.ym))}
    years.add(periods.fy_start_year(periods.default_month()))
    return sorted(years, reverse=True)


def yearly_summary(session, fy, allocation=None):
    """Allocation (or one allocation's sub-allocations) x months of the FY."""
    yms = periods.fy_months(fy)
    query = select(BalanceSubAllocation).where(BalanceSubAllocation.fy == fy)
    if allocation:
        query = query.where(BalanceSubAllocation.allocation == allocation)
    balances = session.scalars(query).all()
    key = (lambda b: b.code) if allocation else (lambda b: b.allocation)
    grid = defaultdict(dict)
    for b in balances:
        cell = grid[key(b)].setdefault(b.ym, [ZERO, ZERO, ZERO])
        last, for_month, to = _bal(b)
        cell[0] += last
        cell[1] += for_month
        cell[2] += to
    months_with_data = [ym for ym in yms if any(ym in g for g in grid.values())]
    columns = [Column("Sub-Allocation" if allocation else "Allocation"), Column("Opening", True)]
    columns += [Column(periods.short_label(ym), True) for ym in yms] + [Column("FY Total", True), Column("Closing", True)]
    table = ReportTable(
        title=("Allocation %s - %s" % (allocation, periods.fy_label(fy))) if allocation else "All allocations - %s" % periods.fy_label(fy),
        columns=columns,
        note="Monthly figures are For The Month. Opening is the first processed month's Last Month; Closing is the latest month's To The Month.")
    order = sorted(grid) if allocation else [a for a in config.ALLOCATIONS if a in grid] + sorted(set(grid) - set(config.ALLOCATIONS))
    for index, k in enumerate(order):
        cells = grid[k]
        first = next((ym for ym in yms if ym in cells), None)
        last_ym = months_with_data[-1] if months_with_data else None
        row = [code_label(k) if allocation else k, cells[first][0] if first else ZERO]
        row += [cells[ym][1] if ym in cells else None for ym in yms]
        row += [sum((cells[ym][1] for ym in cells), ZERO), cells[last_ym][2] if last_ym in cells else ZERO]
        table.rows.append(row)
        table.links[(index, 0)] = ("/sub-allocations/%s?fy=%d" % (k, fy)) if allocation else ("/allocations/%s?fy=%d" % (k, fy))
    if table.rows:
        table.total = ["TOTAL"] + [_sum(table.rows, i) if any(r[i] is not None for r in table.rows) else None for i in range(1, len(columns))]
    return table


# ---- month ---------------------------------------------------------------------------

def month_allocation_codes(session, ym):
    """allocation -> its sub-allocation codes for the month."""
    codes = defaultdict(list)
    for b in session.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.ym == ym)):
        codes[b.allocation].append(b.code)
    return codes


def month_allocations(session, ym):
    """The nine allocations for a month: Last / For / To."""
    balances = session.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.ym == ym)).all()
    by_alloc = defaultdict(lambda: [ZERO, ZERO, ZERO])
    for b in balances:
        for i, v in enumerate(_bal(b)):
            by_alloc[b.allocation][i] += v
    table = ReportTable(title="Allocations - %s" % periods.label(ym),
                        columns=[Column("Allocation"), Column("Last Month", True), Column("For The Month", True), Column("To The Month", True)])
    order = list(config.ALLOCATIONS) + sorted(set(by_alloc) - set(config.ALLOCATIONS))
    for index, allocation in enumerate(order):
        table.rows.append([allocation] + by_alloc[allocation])
        table.links[(index, 0)] = "/months/%s/allocations/%s" % (ym, allocation)
    table.total = ["TOTAL", _sum(table.rows, 1), _sum(table.rows, 2), _sum(table.rows, 3)]
    return table


def allocation_summary(session, ym, allocation):
    """Sub-allocations of one allocation for a month (the Daybook summary with balances)."""
    balances = session.scalars(select(BalanceSubAllocation).where(
        BalanceSubAllocation.ym == ym, BalanceSubAllocation.allocation == allocation).order_by(BalanceSubAllocation.code)).all()
    table = ReportTable(title="Day Book Summary For Allocation Code: %s - %s" % (allocation, periods.label(ym)),
                        columns=[Column("Sub-Allocation"), Column("Last Month", True), Column("For The Month", True),
                                 Column("To The Month", True), Column("Last Month from")])
    sources = {"carried": "previous month", "manual": "entered opening", "fy_reset": "new financial year",
               "missing": "NOT ENTERED"}
    for index, b in enumerate(balances):
        table.rows.append([code_label(b.code)] + list(_bal(b)) + [sources.get(b.opening_source, b.opening_source)])
        table.links[(index, 0)] = "/sub-allocations/%s?fy=%d" % (b.code, b.fy)
    table.total = ["TOTAL", _sum(table.rows, 1), _sum(table.rows, 2), _sum(table.rows, 3), ""]
    return table, balances


# ---- sub-allocation ------------------------------------------------------------------

def sub_allocation(session, code, fy):
    yms = periods.fy_months(fy)
    months = ReportTable(title="Sub-allocation %s - %s, month by month" % (code_label(code), periods.fy_label(fy)),
                         columns=[Column("Month"), Column("Last Month", True), Column("For The Month", True), Column("To The Month", True)])
    balances = {b.ym: b for b in session.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.code == code, BalanceSubAllocation.fy == fy))}
    for ym in yms:
        if ym in balances:
            months.links[(len(months.rows), 0)] = "/months/%s/allocations/%s" % (ym, balances[ym].allocation)
            months.rows.append([periods.label(ym)] + list(_bal(balances[ym])))
    if months.rows:
        months.total = ["FY", months.rows[0][1], _sum(months.rows, 2), months.rows[-1][3]]

    uwid_rows = session.scalars(select(BalanceUwid).where(BalanceUwid.code == code, BalanceUwid.fy == fy).order_by(BalanceUwid.head, BalanceUwid.uwid, BalanceUwid.ym)).all()
    names = _uwid_names(session, {b.uwid for b in uwid_rows})
    per = {}
    for b in uwid_rows:
        entry = per.setdefault((b.head, b.uwid), {"for": ZERO, "last": None, "to": ZERO})
        last, for_month, to = _bal(b)
        entry["for"] += for_month
        entry["last"] = last if entry["last"] is None else entry["last"]
        entry["to"] = to
    uwids = ReportTable(title="Heads and UWIDs in %s - %s" % (code_label(code), periods.fy_label(fy)),
                        columns=[Column("Head"), Column("UWID"), Column("Name"), Column("Opening", True), Column("FY For The Month", True), Column("Closing", True)])
    for index, ((head, uwid), v) in enumerate(sorted(per.items())):
        uwids.rows.append([head_label(head), uwid or "(no UWID)", names.get(uwid, ""), v["last"], v["for"], v["to"]])
        if uwid:
            uwids.links[(index, 1)] = "/uwids/%s?fy=%d" % (uwid, fy)
    if uwids.rows:
        uwids.total = ["TOTAL", "", "", _sum(uwids.rows, 3), _sum(uwids.rows, 4), _sum(uwids.rows, 5)]
    return [months, uwids]


# ---- UWID -----------------------------------------------------------------------------

def _uwid_names(session, uwids):
    uwids = {u for u in uwids if u}
    if not uwids:
        return {}
    return {m.uwid: m.name or "" for m in session.scalars(select(UwidMaster).where(UwidMaster.uwid.in_(uwids)))}


def _scope_filter(query, model, scope):
    if scope is None:
        return query
    conditions = []
    if scope.get("uwid"):
        conditions.append(model.uwid.in_(scope["uwid"]))
    if scope.get("allocation"):
        conditions.append(model.allocation.in_(scope["allocation"]))
    return query.where(or_(*conditions)) if conditions else query.where(false())


def uwid_search(session, text, scope=None, limit=200):
    query = select(BalanceUwid.uwid, func.count(func.distinct(BalanceUwid.ym)), func.sum(BalanceUwid.for_month), func.max(BalanceUwid.ym)).where(BalanceUwid.uwid != "")
    if text:
        query = query.where(BalanceUwid.uwid.like("%" + text.strip() + "%"))
    query = _scope_filter(query, BalanceUwid, scope).group_by(BalanceUwid.uwid).order_by(BalanceUwid.uwid).limit(limit)
    results = session.execute(query).all()
    names = _uwid_names(session, {r[0] for r in results})
    table = ReportTable(title="UWIDs" + (' matching "%s"' % text if text else ""),
                        columns=[Column("UWID"), Column("Name"), Column("Months"), Column("Total booked (all months)", True), Column("Latest month")])
    for index, (uwid, months, total, latest) in enumerate(results):
        table.rows.append([uwid, names.get(uwid, ""), months, total or ZERO, periods.label(latest)])
        table.links[(index, 0)] = "/uwids/%s" % uwid
    if len(results) == limit:
        table.note = "Showing the first %d; refine the search." % limit
    return table


def uwid_detail(session, uwid, fy=None, scope=None):
    """fy=None: all months (overall); else one financial year."""
    query = _scope_filter(select(BalanceUwid).where(BalanceUwid.uwid == uwid), BalanceUwid, scope)
    if fy is not None:
        query = query.where(BalanceUwid.fy == fy)
    balances = session.scalars(query.order_by(BalanceUwid.ym, BalanceUwid.code, BalanceUwid.head)).all()
    span = periods.fy_label(fy) if fy is not None else "all months"

    months = ReportTable(title="UWID %s - month by month (%s)" % (uwid, span),
                         columns=[Column("Month"), Column("Allocation"), Column("Sub-Allocation"), Column("Head"),
                                  Column("Last Month", True), Column("For The Month", True), Column("To The Month", True)])
    for index, b in enumerate(balances):
        months.rows.append([periods.label(b.ym), b.allocation, code_label(b.code), head_label(b.head)] + list(_bal(b)))
        months.links[(index, 0)] = "/months/%s/allocations/%s" % (b.ym, b.allocation)
    if months.rows:
        months.total = ["TOTAL", "", "", "", None, _sum(months.rows, 5), None]

    by_head = ReportTable(title="UWID %s - by sub-allocation and head (%s)" % (uwid, span),
                          columns=[Column("Sub-Allocation"), Column("Head"), Column("For The Month (total)", True), Column("Latest To The Month", True)])
    heads = {}
    for b in balances:
        entry = heads.setdefault((b.code, b.head), [ZERO, ZERO])
        entry[0] += b.for_month
        entry[1] = _bal(b)[2]
    for (code, head), (total, latest) in sorted(heads.items()):
        by_head.rows.append([code_label(code), head_label(head), total, latest])
    if by_head.rows:
        by_head.total = ["TOTAL", "", _sum(by_head.rows, 2), _sum(by_head.rows, 3)]

    entries_query = _scope_filter(select(SuspenseEntry, Month.ym).join(Month, Month.id == SuspenseEntry.month_id)
                                  .where(SuspenseEntry.uwid == uwid, SuspenseEntry.in_daybook.is_(True)), SuspenseEntry, scope)
    if fy is not None:
        entries_query = entries_query.where(Month.ym.in_(periods.fy_months(fy)))
    entries = entries_table(session.execute(entries_query.order_by(Month.ym, SuspenseEntry.row_no)).all(),
                            "Entries booked to UWID %s (%s)" % (uwid, span))
    return [months, by_head, entries], bool(balances)


# ---- entries --------------------------------------------------------------------------

def entries_table(results, title):
    table = ReportTable(title=title, columns=[
        Column("Month"), Column("Alloc."), Column("Head"), Column("Section"), Column("CO6"), Column("CO7"), Column("Date"),
        Column("Party"), Column("Bill desc"), Column("UWID"), Column("Debit", True), Column("Credit", True)])
    for e, ym in results:
        table.rows.append([periods.short_label(ym), e.allocation, head_label(e.head), e.section, e.co6, e.co7, e.book_date,
                           e.party_name, e.bill_desc, e.uwid, e.debit, e.credit])
    if table.rows:
        table.total = ["TOTAL", "", "", "", "", "", "", "", "", "", _sum(table.rows, 10), _sum(table.rows, 11)]
    return table


def entry_search(session, filters, scope=None, limit=1000):
    query = _scope_filter(select(SuspenseEntry, Month.ym).join(Month, Month.id == SuspenseEntry.month_id), SuspenseEntry, scope)
    if filters.get("from"):
        query = query.where(Month.ym >= filters["from"])
    if filters.get("to"):
        query = query.where(Month.ym <= filters["to"])
    if filters.get("allocation"):
        query = query.where(SuspenseEntry.allocation == filters["allocation"])
    if filters.get("code"):
        query = query.where(SuspenseEntry.code == filters["code"].replace("-", ""))
    for field_name in ("uwid", "co6", "section"):
        if filters.get(field_name):
            query = query.where(getattr(SuspenseEntry, field_name).like("%" + filters[field_name].strip() + "%"))
    if filters.get("party"):
        query = query.where(or_(SuspenseEntry.party_name.like("%" + filters["party"] + "%"), SuspenseEntry.bill_desc.like("%" + filters["party"] + "%")))
    if not filters.get("all"):
        query = query.where(SuspenseEntry.in_daybook.is_(True))
    results = session.execute(query.order_by(Month.ym, SuspenseEntry.allocation, SuspenseEntry.row_no).limit(limit)).all()
    table = entries_table(results, "Entries")
    if len(results) == limit:
        table.note = "Showing the first %d entries; narrow the search." % limit
    return table
