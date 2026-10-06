"""Reading and checking AIMS Suspense Head reports.

A report becomes a list of rows (lists of cell values: str or None), the same shape and
values PhpSpreadsheet's toArray() gives the PHP app. AIMS sends "SuspenseHead.xls"
labelled text/plain, so the format is detected from the content, never the headers.
"""
import datetime
import io
import re
from dataclasses import dataclass
from html.parser import HTMLParser

from . import phpcompat as php


class ReportError(ValueError):
    """The file is not a usable Suspense Head report; the message says why."""


# Columns view.php reads by position from each "SECTION" header row
EXPECTED_COLUMNS = {
    0: "SECTION", 1: "CO6 NUMBER", 2: "CO7 NUMBER", 3: "BOOK DATE", 4: "PARTY NAME",
    5: "BILL DESC", 6: "DEBIT", 7: "CREDIT", 11: "UNIQUE_WORK_ID",
}


@dataclass
class Report:
    rows: list
    file_format: str  # xls | xlsx | html
    warnings: list


def _cell_text(value, warnings):
    if value is None:
        return None
    if isinstance(value, str):
        return value
    if isinstance(value, bool):
        return "1" if value else ""
    if isinstance(value, (int, float)):
        # PhpSpreadsheet "General" format: PHP's own number-to-string conversion
        return php.to_string(float(value) if isinstance(value, float) else value)
    if isinstance(value, (datetime.date, datetime.datetime)):
        warnings.append("A date cell was found; it was read as dd/mm/yyyy text.")
        return value.strftime("%d/%m/%Y")
    return str(value)


def _pad(rows):
    width = max((len(r) for r in rows), default=0)
    return [list(r) + [None] * (width - len(r)) for r in rows]


def _read_xlsx(content, warnings):
    from openpyxl import load_workbook

    sheet = load_workbook(io.BytesIO(content), data_only=True).active
    rows = [[_cell_text(v, warnings) for v in row] for row in sheet.iter_rows(min_row=1, min_col=1, values_only=True)]
    return rows


def _read_xls(content, warnings):
    import xlrd

    book = xlrd.open_workbook(file_contents=content)
    sheet = book.sheet_by_index(0)
    rows = []
    for r in range(sheet.nrows):
        row = []
        for c in range(sheet.ncols):
            cell = sheet.cell(r, c)
            if cell.ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
                row.append(None)
            elif cell.ctype == xlrd.XL_CELL_DATE:
                row.append(_cell_text(xlrd.xldate.xldate_as_datetime(cell.value, book.datemode), warnings))
            elif cell.ctype == xlrd.XL_CELL_NUMBER:
                v = cell.value
                row.append(_cell_text(int(v) if v.is_integer() and abs(v) < 2**53 else v, warnings))
            else:
                row.append(_cell_text(cell.value, warnings))
        rows.append(row)
    return rows


class _TableParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.rows, self._row, self._cell = [], None, None

    def handle_starttag(self, tag, attrs):
        if tag == "tr":
            self._row = []
        elif tag in ("td", "th") and self._row is not None:
            self._cell = []
            self._span = int(dict(attrs).get("colspan") or 1)

    def handle_endtag(self, tag):
        if tag in ("td", "th") and self._cell is not None:
            text = "".join(self._cell).strip()
            self._row.append(text if text != "" else None)
            self._row.extend([None] * (self._span - 1))
            self._cell = None
        elif tag == "tr" and self._row is not None:
            self.rows.append(self._row)
            self._row = None

    def handle_data(self, data):
        if self._cell is not None:
            self._cell.append(data)


def _read_html(content):
    parser = _TableParser()
    parser.feed(content.decode("utf-8", errors="replace"))
    return parser.rows


def read_report(content: bytes) -> Report:
    """Read any format AIMS may send: .xls (BIFF), .xlsx, or an HTML table."""
    warnings = []
    head = content[:4096]
    try:
        if head.startswith(b"\xD0\xCF\x11\xE0"):
            rows, fmt = _read_xls(content, warnings), "xls"
        elif head.startswith(b"PK"):
            rows, fmt = _read_xlsx(content, warnings), "xlsx"
        elif re.search(rb"<table", head, re.I) or re.search(rb"<table", content, re.I):
            rows, fmt = _read_html(content), "html"
        elif re.search(rb"<(!doctype html|html|body|form)\b", head, re.I):
            raise ReportError("This is a web page (such as an AIMS login or error page), not the Suspense Head report.")
        else:
            raise ReportError("The file is not a spreadsheet AIMS would send (.xls, .xlsx or HTML table).")
    except ReportError:
        raise
    except Exception as e:  # corrupt file
        raise ReportError("The file is not a readable spreadsheet (%s)." % e)
    return Report(rows=_pad(rows), file_format=fmt, warnings=warnings)


def _clip(text, n=80):
    text = "" if text is None else str(text)
    return text if len(text) <= n else text[: n - 3] + "..."


def period_strings(year, month):
    """AIMS form dates for a month, e.g. (1/9/2026, 30/9/2026)."""
    import calendar

    return "1/%d/%d" % (month, year), "%d/%d/%d" % (calendar.monthrange(year, month)[1], month, year)


def validate(rows, allocation, year, month):
    """Check the report is `allocation`'s, for the month, laid out as view.php reads it.

    Returns (sub-allocation headings, transaction rows)."""
    heading = (rows[1][0] if len(rows) > 1 and rows[1] and rows[1][0] is not None else "").strip()
    if "SUSPENSE HEAD" not in heading.upper():
        raise ReportError('Not a Suspense Head report: row 2 should read "SUSPENSE HEAD REPORT FROM ..." but reads "%s".' % _clip(heading))
    m = re.search(r"FROM\s+(\d{1,2})/(\d{1,2})/(\d{4})\s+TO\s+(\d{1,2})/(\d{1,2})/(\d{4})", heading, re.I)
    if not m:
        raise ReportError('Report period not found in heading "%s".' % _clip(heading))
    found = ("%d/%d/%d" % tuple(int(x) for x in m.group(1, 2, 3)), "%d/%d/%d" % tuple(int(x) for x in m.group(4, 5, 6)))
    expected = period_strings(year, month)
    if found != expected:
        raise ReportError("Report is for %s to %s, expected %s to %s." % (found + expected))

    heads = entries = 0
    for index, row in enumerate(rows):
        first = row[0] if row and row[0] is not None else ""
        if "ALLOCATION " in first:
            if php.substr(first, 13, 2) != str(allocation):
                raise ReportError("File contains allocation %s (row %d); expected only allocation %s." % (php.substr(first, 13, 8), index + 1, allocation))
            heads += 1
        elif first.strip().upper() == "SECTION":
            for column, name in EXPECTED_COLUMNS.items():
                actual = (row[column] if column < len(row) and row[column] is not None else "").strip().upper()
                if actual != name:
                    raise ReportError('Column layout changed: column %d of row %d is "%s", expected "%s".' % (column + 1, index + 1, _clip(actual), name))
        elif first.strip() != "" and heads > 0:
            entries += 1
    return heads, entries


def write_xlsx(rows) -> bytes:
    """The report as .xlsx with every cell as text (the form the PHP app reads)."""
    from openpyxl import Workbook

    book = Workbook()
    sheet = book.active
    for r, row in enumerate(rows, start=1):
        for c, value in enumerate(row, start=1):
            if value is not None:
                cell = sheet.cell(row=r, column=c)
                cell.value = value
                cell.data_type = "s"
    out = io.BytesIO()
    book.save(out)
    return out.getvalue()
