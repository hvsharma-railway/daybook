"""How each Daybook table fits on a printed page (browser Print and the PDF use the same layout).

A4 landscape leaves 281 mm across. A table is printed at the largest font size (8 pt down to
5.5 pt) at which all its columns fit. If even 5.5 pt is too wide, its head columns are split
into consecutive groups printed on separate pages; each repeats the Sec./CO6, description and
UWID columns, and Total is printed with the last group. Nothing is ever cut off.

Column widths are estimated from the longest text printed in each column, generously, so the
estimate errs on the wide side.
"""
from dataclasses import dataclass, field

from ..labels import head_label

PAGE_WIDTH_MM = 297.0 - 2 * 8.0
FIXED_MM = (30.0, 46.0)                # Sec./CO6 and BILL DESC / PARTY NAME (these wrap)
FONT_SIZES_PT = (8.0, 7.0, 6.5, 6.0, 5.5)
CHAR_EM = 0.70                         # width of a digit in em, allowing for the bold TOTAL row (DejaVu Sans Bold ~0.70)
PT_MM = 0.3528
PADDING_MM = 2.6                       # cell padding + borders


@dataclass
class Part:
    heads: list
    with_total: bool
    first: int                          # 1-based column numbers among the heads
    last: int
    head_count: int
    widths: list = field(default_factory=list)   # mm, in printed column order

    @property
    def suffix(self):
        if self.first == 1 and self.last == self.head_count:
            return ""
        prefix = "continued, " if self.first > 1 else ""
        return " (%scolumns %d-%d of %d)" % (prefix, self.first, self.last, self.head_count)

    @property
    def table_width(self):
        return "%.1f" % sum(self.widths)


@dataclass
class TableLayout:
    table: object
    font_pt: float
    parts: list


def _chars(texts):
    return max((len(line) for text in texts for line in str(text).split("\n")), default=1)


def _width(chars, font_pt):
    return chars * CHAR_EM * font_pt * PT_MM + PADDING_MM


def _uwid_width(table, font_pt):
    """UWID column: wide enough for the longest UWID on one line (at least the heading)."""
    return _width(_chars(["UWID"] + [row.uwid_text for row in table.rows]), font_pt)


def _fixed(table, font_pt):
    return FIXED_MM[0] + FIXED_MM[1] + _uwid_width(table, font_pt)


def _column_chars(table):
    heads = {}
    for h in table.heads:
        texts = [head_label(h), table.head_total_display(h)]
        for row in table.rows:
            shown = row.cell_display(h)
            texts.extend(shown if shown is not None else ["---"])
        heads[h] = _chars(texts)
    total = _chars(["Total", table.total_display] + [row.total_display for row in table.rows])
    return heads, total


def _with_widths(part, head_widths, total_width, uwid_width):
    widths = [head_widths[h] for h in part.heads] + ([total_width] if part.with_total else [])
    fixed = [FIXED_MM[0], FIXED_MM[1], uwid_width]
    spare = PAGE_WIDTH_MM - sum(fixed) - sum(widths)
    if spare > 0:
        fixed[1] += spare                # give leftover room to the description column
    part.widths = fixed + widths
    return part


def _split(heads, head_widths, total_width, fixed_width):
    """Consecutive groups of heads that each fit next to the fixed columns (Total with the last)."""
    room = PAGE_WIDTH_MM - fixed_width
    groups, current, used = [], [], 0.0
    for h in heads:
        if current and used + head_widths[h] > room:
            groups.append(current)
            current, used = [], 0.0
        current.append(h)
        used += head_widths[h]
    groups.append(current)
    # The last group must also hold Total: move its first heads back one group (or into a new one) until it fits
    def used_by(group):
        return sum(head_widths[h] for h in group)

    while used_by(groups[-1]) + total_width > room:
        last = groups[-1]
        if len(last) == 1:
            groups.append([])            # Total on its own
            break
        moved = last.pop(0)
        if len(groups) > 1 and used_by(groups[-2]) + head_widths[moved] <= room:
            groups[-2].append(moved)
        else:
            groups.insert(len(groups) - 1, [moved])
    return groups


def layout_table(table):
    head_chars, total_chars = _column_chars(table)
    n = len(table.heads)
    for font in FONT_SIZES_PT:
        head_widths = {h: _width(c, font) for h, c in head_chars.items()}
        total_width = _width(total_chars, font)
        if _fixed(table, font) + sum(head_widths.values()) + total_width <= PAGE_WIDTH_MM:
            part = Part(heads=list(table.heads), with_total=True, first=1, last=n, head_count=n)
            return TableLayout(table, font, [_with_widths(part, head_widths, total_width, _uwid_width(table, font))])

    font = FONT_SIZES_PT[-1]
    head_widths = {h: _width(c, font) for h, c in head_chars.items()}
    total_width = _width(total_chars, font)
    parts, first = [], 1
    groups = _split(list(table.heads), head_widths, total_width, _fixed(table, font))
    for i, group in enumerate(groups):
        part = Part(heads=group, with_total=(i == len(groups) - 1), first=first, last=first + len(group) - 1, head_count=n)
        parts.append(_with_widths(part, head_widths, total_width, _uwid_width(table, font)))
        first += len(group)
    return TableLayout(table, font, parts)


def layout(daybook):
    return [layout_table(t) for t in daybook.tables]
