"""IPAS Capital Schedule: reading it and checking the Daybook against it.

IPAS returns CapitalSchedule.html (one <html> block per schedule, loosely formed). Three
blocks are used:
  CAPITAL-2 "FOR THE MONTH OF ..."     plan heads x fund columns, VOTED / CHARGED lines
  CAPITAL-2 "TO END OF THE MONTH ..."  the same, progressive for the financial year
  CAPITAL-8 "BIFURCATION OF DF-I,II,III,IV"  DF-I..IV per plan head, for the month and to end

The Daybook holds voted figures only, so it is compared with the VOTED lines. Schedule row
"16.Traffic facilities..." under CAPITAL corresponds to Daybook sub-allocation 20-16, and so on
(COLUMN_ALLOCATIONS). Sub-allocations in DEDUCT_CREDIT_CODES (CR-RM) are compared, sign
reversed, with the "Deduct Credit Including Receipts Capital A/cs" row of their column.
Last Month on the Schedule is To End minus For The Month.
"""
import calendar
import re
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Optional

from sqlalchemy import select

from . import periods
from .models import BalanceSubAllocation, StoredFile

ZERO = Decimal("0.00")

# Schedule fund column (as headed in the file) -> Daybook allocations whose figures it holds
COLUMN_ALLOCATIONS = {
    "CAPITAL": ("20",),
    "DRF": ("21",),
    "DF I,II,III,IV": ("23", "33", "43", "53"),
    "SAFETY FUND": ("26",),
    "CAPITAL N": ("28",),
    "RRSK": ("29",),
}
# Bifurcation table DF-I..IV -> allocation
DF_ALLOCATIONS = ("23", "33", "43", "53")
# Sub-allocations shown on the Schedule's "Deduct Credit" row (sign reversed) instead of a plan head
DEDUCT_CREDIT_CODES = {"CRRM": "RRSK"}


class CapitalScheduleError(ValueError):
    pass


@dataclass
class Row:
    label: str
    kind: str                         # plan | total_final | suspense | summary
    plan_head: Optional[str] = None   # "16"
    voted: list = field(default_factory=list)
    charged: Optional[list] = None


@dataclass
class Section:
    key: str                          # for | to
    title: str
    columns: list
    rows: list

    def deduct_row(self):
        return next((r for r in self.rows if r.kind == "summary" and r.label.upper().startswith("DEDUCT CREDIT")), None)

    def plan_row(self, plan_head):
        return next((r for r in self.rows if r.kind == "plan" and r.plan_head == plan_head), None)


@dataclass
class DfRow:
    label: str
    plan_head: Optional[str]
    values: list                      # DF-I..IV, total (for the month), DF-I..IV, total (to end)
    is_total: bool = False


@dataclass
class Schedule:
    month_label: str
    for_month: Section
    to_end: Section
    df_rows: list

    def df_values(self, plan_head):
        """DF-I..IV + total, for and to end, summed over a plan head's rows (53 has two)."""
        rows = [r for r in self.df_rows if r.plan_head == plan_head and not r.is_total]
        if not rows:
            return None
        return [sum((r.values[i] for r in rows), ZERO) for i in range(10)]


# ---- reading -----------------------------------------------------------------------

_TAG = re.compile(r"<[^>]+>")


def _text(html):
    return re.sub(r"\s+", " ", _TAG.sub(" ", html).replace("&nbsp;", " ").replace("&amp;", "&")).strip()


def _cells(row_html):
    return [_text(c) for c in re.findall(r"<td[^>]*>(.*?)</td>", row_html, re.I | re.S)]


def _rows(block):
    return [_cells(r) for r in re.findall(r"<tr[^>]*>(.*?)</tr>", block, re.I | re.S)]


def _number(text):
    try:
        return Decimal(text.replace(",", "").strip()).quantize(Decimal("0.01"))
    except (InvalidOperation, AttributeError):
        return None


def _plan_head(label):
    m = re.match(r"\s*(\d{2})\s*\.", label)
    return m.group(1) if m else None


def _month_label(ym):
    year, month = periods.parse_ym(ym)
    return calendar.month_name[month].upper(), "%02d" % (year % 100)


def _check_month(block_text, ym, what):
    m = re.search(r"(?:MONTH OF|OF THE MONTH|END OF MONTH)\s+([A-Z]+)\s*(\d{2})\b", block_text.upper())
    if not m:
        raise CapitalScheduleError("The month of the %s could not be found." % what)
    name, yy = _month_label(ym)
    if (m.group(1), m.group(2)) != (name, yy):
        raise CapitalScheduleError("The Capital Schedule is for %s %s, expected %s %s." % (m.group(1).title(), m.group(2), name.title(), yy))
    return "%s %s" % (m.group(1).title(), m.group(2))


def _section(block, key, title):
    rows = _rows(block)
    header = next((r for r in rows if any(c.upper() == "SUB-HEAD OF DEMAND" for c in r) and r and r[-1].upper() == "TOTAL"), None)
    if header is None:
        raise CapitalScheduleError("Column headings not found in %s." % title)
    start = [c.upper() for c in header].index("VOTED/CHARGED") + 1
    columns = [re.sub(r"\s+", " ", c.upper()) for c in header[start:]]
    n = len(columns)
    section, current = Section(key=key, title=title, columns=columns, rows=[]), None
    for cells in rows:
        if len(cells) == n + 2 and cells[1].upper() == "VOTED":
            values = [_number(c) for c in cells[2:]]
            label = cells[0]
            kind = "total_final" if label.upper().startswith("TOTAL") else "plan"
            current = Row(label=label, kind=kind, plan_head=_plan_head(label) if kind == "plan" else None, voted=values)
            section.rows.append(current)
        elif len(cells) == n + 1 and cells[0].upper() == "CHARGED" and current is not None:
            current.charged = [_number(c) for c in cells[1:]]
        elif len(cells) == n + 1 and all(_number(c) is not None for c in cells[1:]):
            label = cells[0]
            kind = "suspense" if re.match(r"\d{4}\b", label) else "summary"
            section.rows.append(Row(label=label, kind=kind, voted=[_number(c) for c in cells[1:]]))
    if not any(r.kind == "plan" for r in section.rows):
        raise CapitalScheduleError("No plan head rows found in %s." % title)
    if any(None in r.voted for r in section.rows):
        raise CapitalScheduleError("Unreadable figures in %s." % title)
    return section


def _df_rows(block):
    rows, current_head = [], None
    for cells in _rows(block):
        if len(cells) != 11:
            continue
        values = [_number(c) for c in cells[1:]]
        if None in values:
            continue
        label = cells[0]
        head = _plan_head(label)
        is_total = label.upper().startswith("TOTAL")
        if head is None and not is_total:
            head = current_head          # e.g. " 11)Others Railway Users Amenities" under 53
        current_head = head if not is_total else current_head
        rows.append(DfRow(label=label, plan_head=head, values=values, is_total=is_total))
    if not rows:
        raise CapitalScheduleError("No rows found in the DF bifurcation (CAPITAL-8).")
    return rows


def decode(content):
    for encoding in ("utf-8", "cp1252"):
        try:
            return content.decode(encoding)
        except UnicodeDecodeError:
            pass
    return content.decode("latin-1")


def parse(content, ym):
    """content: the CapitalSchedule.html bytes. Raises CapitalScheduleError with the reason."""
    html = decode(content) if isinstance(content, bytes) else content
    blocks = re.split(r"<html", html, flags=re.I)
    for_block = to_block = df_block = None
    for block in blocks:
        upper = _text(block).upper()
        if "BIFURCATION OF DF" in upper:
            df_block = block
        elif re.search(r">\s*CAPITAL-2\s*<", block, re.I) and "GRANT NO-16" in upper:
            if "TO END OF THE MONTH" in upper:
                to_block = block
            elif "FOR THE MONTH OF" in upper:
                for_block = block
    if for_block is None or to_block is None or df_block is None:
        missing = [n for n, b in (("CAPITAL-2 for the month", for_block), ("CAPITAL-2 to end of month", to_block), ("CAPITAL-8 DF bifurcation", df_block)) if b is None]
        raise CapitalScheduleError("This is not a complete Capital Schedule: %s not found." % ", ".join(missing))
    label = _check_month(_text(for_block), ym, "Capital Schedule")
    _check_month(_text(to_block), ym, "to-end schedule")
    return Schedule(
        month_label=label,
        for_month=_section(for_block, "for", "Works expenditure under Grant No-16 - for the month"),
        to_end=_section(to_block, "to", "Works expenditure under Grant No-16 - to end of the month"),
        df_rows=_df_rows(df_block),
    )


# ---- checking ----------------------------------------------------------------------

@dataclass
class Cell:
    status: str                       # match | differs | zero
    daybook: Decimal


@dataclass
class CodeCheck:
    where: str                        # e.g. "CAPITAL, row 16"
    statuses: dict                    # last / for / to -> match | differs | missing
    schedule: dict                    # last / for / to -> Schedule figure (or None)

    @property
    def ok(self):
        return all(s == "match" for s in self.statuses.values())


@dataclass
class AllocationTotal:
    where: str
    statuses: dict                    # last / for / to -> match | differs
    schedule: dict
    daybook: dict


@dataclass
class Verification:
    schedule: Schedule
    cells: dict                       # (section key, row index, column index) or ("df", row index, value index) -> Cell
    codes: dict                       # sub-allocation code -> CodeCheck
    differences: list
    totals: dict = field(default_factory=dict)   # allocation -> AllocationTotal

    def allocation_counts(self, allocation, codes_of_allocation):
        checks = [self.codes[c] for c in codes_of_allocation if c in self.codes]
        return sum(1 for c in checks if c.ok), len(checks)

    @property
    def matched_cells(self):
        return sum(1 for c in self.cells.values() if c.status == "match")

    @property
    def differing_cells(self):
        return sum(1 for c in self.cells.values() if c.status == "differs")


def _status(schedule_value, daybook_value):
    if schedule_value == daybook_value:
        return "zero" if schedule_value == ZERO else "match"
    return "differs"


def load(session, month):
    """The month's stored Capital Schedule, parsed, or None."""
    if not month.capital_file_id:
        return None
    stored = session.get(StoredFile, month.capital_file_id)
    return parse(stored.content, month.ym) if stored else None


def try_verify(session, month):
    """(Verification or None, error message or None) - for pages that show marks when they can."""
    try:
        return verify(session, month), None
    except CapitalScheduleError as e:
        return None, str(e)


def verify(session, month, schedule=None):
    schedule = schedule or load(session, month)
    if schedule is None:
        return None
    balances = {b.code: b for b in session.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.ym == month.ym))}

    def daybook(code, which):
        b = balances.get(code)
        if b is None:
            return ZERO
        return {"last": b.last_month_fy, "for": b.for_month, "to": b.to_month_fy}[which].quantize(Decimal("0.01"))

    cells, codes, differences = {}, {}, []
    sections = {"for": schedule.for_month, "to": schedule.to_end}

    # Schedule cells (VOTED lines): plan heads, TOTAL-FINAL HEADS and the Deduct Credit row
    for key, section in sections.items():
        for ci, column in enumerate(section.columns):
            allocations = COLUMN_ALLOCATIONS.get(column)
            if not allocations:
                continue
            deduct_codes = [c for c, col in DEDUCT_CREDIT_CODES.items() if col == column]
            for ri, row in enumerate(section.rows):
                if row.kind == "plan":
                    value = sum((daybook(a + row.plan_head, key) for a in allocations), ZERO)
                elif row.kind == "total_final":
                    value = sum((daybook(code, key) for code, b in balances.items()
                                 if b.allocation in allocations and code not in DEDUCT_CREDIT_CODES), ZERO)
                elif row is section.deduct_row():
                    value = -sum((daybook(c, key) for c in deduct_codes), ZERO)
                else:
                    continue
                cell = Cell(_status(row.voted[ci], value), value)
                cells[(key, ri, ci)] = cell
                if cell.status == "differs":
                    differences.append({"where": "%s - %s, %s" % ("For the month" if key == "for" else "To end", row.label, column),
                                        "schedule": row.voted[ci], "daybook": value})

    # DF bifurcation table
    for ri, row in enumerate(schedule.df_rows):
        if row.is_total or row.plan_head is None:
            continue
        for di, allocation in enumerate(DF_ALLOCATIONS):
            for offset, key in ((0, "for"), (5, "to")):
                value = daybook(allocation + row.plan_head, key)
                summed = schedule.df_values(row.plan_head)[offset + di]
                # rows split under one plan head (53) are compared on their sum, shown on the first row
                first = next(i for i, r in enumerate(schedule.df_rows) if r.plan_head == row.plan_head and not r.is_total)
                if ri != first:
                    continue
                cell = Cell(_status(summed, value), value)
                cells[("df", ri, offset + di)] = cell
                if cell.status == "differs":
                    differences.append({"where": "DF bifurcation - %s, DF-%s %s" % (row.label, "I II III IV".split()[di], "for the month" if key == "for" else "to end"),
                                        "schedule": summed, "daybook": value})

    # Each Daybook sub-allocation: For / Last / To End against its Schedule figure
    for code, b in balances.items():
        allocation = b.allocation
        figures = None
        if code in DEDUCT_CREDIT_CODES:
            column = DEDUCT_CREDIT_CODES[code]
            rows = {k: s.deduct_row() for k, s in sections.items()}
            if all(rows.values()) and column in schedule.for_month.columns:
                ci = schedule.for_month.columns.index(column)
                figures = {"for": -rows["for"].voted[ci], "to": -rows["to"].voted[ci]}
                where = "%s, Deduct Credit row" % column
        elif allocation in DF_ALLOCATIONS:
            values = schedule.df_values(code[2:])
            if values is not None:
                di = DF_ALLOCATIONS.index(allocation)
                figures = {"for": values[di], "to": values[5 + di]}
                where = "DF-%s, row %s" % ("I II III IV".split()[di], code[2:])
        else:
            column = next((col for col, allocs in COLUMN_ALLOCATIONS.items() if allocation in allocs), None)
            if column and column in schedule.for_month.columns:
                ci = schedule.for_month.columns.index(column)
                rows = {k: s.plan_row(code[2:]) for k, s in sections.items()}
                if all(rows.values()):
                    figures = {"for": rows["for"].voted[ci], "to": rows["to"].voted[ci]}
                    where = "%s, row %s" % (column, code[2:])
        if figures is None:
            codes[code] = CodeCheck(where="not in Capital Schedule", statuses={"last": "missing", "for": "missing", "to": "missing"},
                                    schedule={"last": None, "for": None, "to": None})
            continue
        figures["last"] = figures["to"] - figures["for"]
        statuses = {k: ("match" if figures[k] == daybook(code, k) else "differs") for k in ("last", "for", "to")}
        codes[code] = CodeCheck(where=where, statuses=statuses, schedule=figures)
    # Each allocation's total (the summary TOTAL row) against the Schedule's total for its fund:
    # TOTAL-FINAL HEADS less the Deduct Credit row (so CR-RM is included), or the DF bifurcation TOTAL row
    totals = {}
    df_total_index = next((i for i, r in enumerate(schedule.df_rows) if r.is_total), None)
    for allocation in sorted({b.allocation for b in balances.values()}):
        figures = None
        if allocation in DF_ALLOCATIONS:
            if df_total_index is not None:
                di = DF_ALLOCATIONS.index(allocation)
                row = schedule.df_rows[df_total_index]
                figures = {"for": row.values[di], "to": row.values[5 + di]}
                where = "DF bifurcation TOTAL, DF-%s" % "I II III IV".split()[di]
        else:
            column = next((col for col, allocs in COLUMN_ALLOCATIONS.items() if allocation in allocs), None)
            if column and column in schedule.for_month.columns:
                ci = schedule.for_month.columns.index(column)
                final = {k: next((r for r in sec.rows if r.kind == "total_final"), None) for k, sec in sections.items()}
                if all(final.values()):
                    figures = {}
                    for k, sec in sections.items():
                        deduct = sec.deduct_row()
                        figures[k] = final[k].voted[ci] - (deduct.voted[ci] if deduct else ZERO)
                    where = "%s, Total final heads less Deduct Credit" % column
        if figures is None:
            continue
        figures["last"] = figures["to"] - figures["for"]
        totals_daybook = {k: sum((daybook(code, k) for code, b in balances.items() if b.allocation == allocation), ZERO)
                          for k in ("last", "for", "to")}
        totals[allocation] = AllocationTotal(
            where=where, schedule=figures, daybook=totals_daybook,
            statuses={k: ("match" if figures[k] == totals_daybook[k] else "differs") for k in ("last", "for", "to")})

    # The DF bifurcation TOTAL row, per DF, against the DF allocation's total
    if df_total_index is not None:
        row = schedule.df_rows[df_total_index]
        for di, allocation in enumerate(DF_ALLOCATIONS):
            for offset, key in ((0, "for"), (5, "to")):
                value = sum((daybook(code, key) for code, b in balances.items() if b.allocation == allocation), ZERO)
                cell = Cell(_status(row.values[offset + di], value), value)
                cells[("df", df_total_index, offset + di)] = cell
                if cell.status == "differs":
                    differences.append({"where": "DF bifurcation - TOTAL, DF-%s %s" % ("I II III IV".split()[di], "for the month" if key == "for" else "to end"),
                                        "schedule": row.values[offset + di], "daybook": value})
    return Verification(schedule=schedule, cells=cells, codes=codes, differences=differences, totals=totals)


def accept(session, month, content, file_name, via, user_id=None):
    """Check and store a Capital Schedule for the month. Raises CapitalScheduleError with the reason."""
    from .storage import log_event, store_file

    schedule = parse(content, month.ym)
    stored = store_file(session, month.id, "capital_schedule", None, "CapitalSchedule_%s.html" % month.ym, content)
    month.capital_file_id = stored.id
    month.capital_status, month.capital_message = "downloaded", None
    log_event(session, "capital_schedule_received", month.ym, message="%s via %s (%s)" % (file_name, via, schedule.month_label), user_id=user_id)
    return schedule
