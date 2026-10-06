"""Shared test helpers: fixtures and reading view.php's HTML into comparable grids."""
import re
from pathlib import Path

from daybook import phpcompat as php
from daybook.suspense import read_report

HERE = Path(__file__).parent
FIXTURES = HERE / "fixtures"
GOLDEN = HERE / "golden"

# golden name -> fixture file
VIEW_CASES = {
    **{"aug2026_%d" % n: FIXTURES / "aug2026" / ("%d.xlsx" % n) for n in range(1, 10)},
    "feb2026": FIXTURES / "feb2026" / "BOOK.xlsx",
    "edge20": FIXTURES / "synthetic" / "edge20.xlsx",
    "noise21": FIXTURES / "synthetic" / "noise21.xlsx",
}


def fixture_rows(path):
    return read_report(Path(path).read_bytes()).rows


def golden_grid(html):
    """view.php output -> {'tables': [{title, header, rows}], 'summary': {...}} with cells as raw HTML."""
    html = re.sub(r"<!--.*?-->", "", html, flags=re.S)
    tables = []
    for m in re.finditer(r'<div id="table(?!Summary)[^"]*">(.*?)</table>', html, re.S):
        block = m.group(1)
        title = re.search(r"<h3><u>(.*?)</u></h3>", block, re.S).group(1)
        header = re.findall(r"<th(?:\s[^>]*)?>(.*?)</th>", block, re.S)
        body = block.split("<tbody>", 1)[1]
        rows = [re.findall(r"<td[^>]*>(.*?)</td>", r, re.S) for r in re.findall(r"<tr[^>]*>(.*?)</tr>", body, re.S)]
        tables.append({"title": title, "header": header, "rows": rows})
    summary = re.search(r'<div id="tableSummary">(.*?)</table>', html, re.S).group(1)
    summary_title = re.search(r"<h3>(.*?)</h3>", summary, re.S).group(1)
    summary_rows = []
    for r in re.findall(r"<tr[^>]*>(.*?)</tr>", summary.split("<tbody>", 1)[1], re.S):
        cells = re.findall(r"<td[^>]*>(.*?)</td>", r, re.S)
        summary_rows.append([re.sub(r"<[^>]+>", "", c).strip() for c in (cells[0], cells[2], cells[3])])
    return {"tables": tables, "summary": {"title": summary_title, "rows": summary_rows}}


def engine_grid(daybook):
    """The engine's Daybook printed the way view.php prints it."""
    tables = []
    for t in daybook.tables:
        header = ["Sec. - CO6 / CO7", "BILL DESC / PARTY NAME ", "UWID "] + list(t.heads) + ["Total"]
        rows = []
        for r in t.rows:
            first, second = r.label
            cells = [first + "<br/>Dt: " + second[4:], r.description + "<br/>" + "<br/><br/><br/>", r.uwid_text + "<br/><br/><br/>"]
            for h in t.heads:
                shown = r.cell_display(h)
                cells.append("---" if shown is None else "<br/>".join(shown))
            cells.append(r.total_display)
            rows.append(cells)
        rows.append(["<b>TOTAL</b>"] + ["<b>" + t.head_total_display(h) + "</b>" for h in t.heads] + ["<b>" + t.total_display + "</b>"])
        tables.append({"title": " " + daybook.table_title(t) + " ", "header": header, "rows": rows})
    summary_rows = [[t.label, t.total_display, t.total_display] for t in daybook.tables]
    summary_rows.append(["TOTAL", daybook.month_total_display, daybook.month_total_display])
    title = "Day Book Summary For Allocation Code: " + daybook.allocation
    return {"tables": tables, "summary": {"title": title, "rows": summary_rows}}
