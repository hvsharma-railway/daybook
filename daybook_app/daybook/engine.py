"""The Daybook engine: view.php's rules, in one place.

build_daybook(rows) turns a Suspense Head report (rows as read by suspense.read_report)
into tables, rows, totals and the summary exactly as view.php computes and prints them,
using PHP 7.4 value semantics (phpcompat) so every printed figure matches.

Alongside each printed figure the engine keeps an exact Decimal amount (sum of the
2-decimal values that make up row totals) for the balances ledger.
"""
from dataclasses import dataclass, field
from decimal import Decimal

from . import phpcompat as php

_FIELDS = ("SECTION", "CO6", "CO7", "BOOK DATE", "PARTY NAME", "BILL DESC", "DEBIT", "CREDIT", "UNIQUE_WORK_ID")


@dataclass
class DaybookRow:
    number: int
    section: object
    co6: object
    co7: object
    book_date: object
    party_name: object
    bill_desc: object
    uwid: object
    is_jv: bool
    values: dict            # head -> list of PHP values, as printed
    total_display: str      # row "Total" cell
    amounts: dict           # head -> Decimal (sum of the 2-decimal values)

    @property
    def label(self):
        """First cell, as two lines (view.php joins them with <br/>)."""
        if self.is_jv:
            first = "%d JV - %s" % (self.number, php.to_string(self.co6))
        else:
            first = "%d %s - %s / %s" % (self.number, php.to_string(self.section),
                                          php.substr(self.co6, -4), php.substr(self.co7, -4))
        return first, "Dt: " + php.to_string(self.book_date)

    @property
    def description(self):
        return php.to_string(self.bill_desc) + " M/S " + php.to_string(self.party_name)

    @property
    def uwid_text(self):
        return php.to_string(self.uwid)

    def cell_display(self, head):
        """Printed values of one head column, or None for '---'."""
        if head not in self.values:
            return None
        return [php.to_string(v) for v in self.values[head]]

    @property
    def amount(self):
        return sum(self.amounts.values(), Decimal("0"))


@dataclass
class DaybookTable:
    code: str               # 4 characters, e.g. "2016"
    heads: list             # sub-allocation heads (columns), e.g. ["20164103", ...]
    rows: list
    head_totals: dict       # head -> PHP value printed in the TOTAL row
    total: object           # PHP value printed as the table total (= For The Month)

    @property
    def label(self):
        return php.substr(self.code, 0, 2) + "-" + php.substr(self.code, 2, 4)

    def head_total_display(self, head):
        return php.to_string(self.head_totals[head])

    @property
    def total_display(self):
        return php.to_string(self.total)

    @property
    def amount(self):
        return sum((r.amount for r in self.rows), Decimal("0"))


@dataclass
class Daybook:
    heading: object         # report heading (row 2)
    allocation: str         # view.php's $dayBookFor, e.g. "20"
    tables: list
    month_total: object     # PHP value printed as the summary TOTAL
    warnings: list = field(default_factory=list)

    @property
    def period_text(self):
        """Text after "SUSPENSE HEAD   REPORT " used in table titles, e.g. "FROM 1/8/2026 TO 31/8/2026"."""
        return php.substr(self.heading, 22)

    def table_title(self, table):
        return "DAY BOOK " + self.period_text + " FOR THE ALLOCATION " + table.label

    @property
    def month_total_display(self):
        return php.to_string(self.month_total)


def _include(code):
    n = php.to_number(php.substr(code, 2, 4))
    return n < 66 or n == 81 or n == 83


def _cell(row, i):
    return row[i] if i < len(row) else None


def _contains(haystack, needle):
    return needle in php.to_string(haystack)


def build_daybook(rows):
    """Port of view.php: parse allocations, build each table, then the summary."""
    warnings = []
    heading = rows[1][0] if len(rows) > 1 and rows[1] else None

    allocations = {}
    code = sub_code = day_book_for = ""
    for t in rows[1:]:  # view.php unsets the first row
        t0 = _cell(t, 0)
        if _contains(t0, "ALLOCATION "):
            code = php.substr(t0, 13, 4)
            sub_code = php.substr(t0, 13, 8)
            if _include(code):
                if code in allocations:
                    if sub_code in allocations[code]:
                        warnings.append("Head %s appears twice in the report; as in the existing Daybook, only its last block is used." % sub_code)
                    allocations[code][sub_code] = []
                else:
                    allocations[code] = {sub_code: []}
            if day_book_for == "":
                day_book_for = php.substr(t0, 13, 2)
        elif (t0 is not None and t0 != "" and t0 != "SECTION" and not _contains(_cell(t, 4), "SYS-GENERATED")
              and code != "" and sub_code != "" and _include(code)):
            allocations[code][sub_code].append({
                "SECTION": t0, "CO6": _cell(t, 1), "CO7": _cell(t, 2), "BOOK DATE": _cell(t, 3),
                "PARTY NAME": _cell(t, 4), "BILL DESC": _cell(t, 5), "DEBIT": _cell(t, 6),
                "CREDIT": _cell(t, 7), "UNIQUE_WORK_ID": _cell(t, 11) if _cell(t, 11) is not None else "",
            })

    tables = []
    month_total = 0
    for code, subs in allocations.items():
        heads = list(subs.keys())
        totals = {k: 0 for k in heads}
        groups = {}
        for key, entries in subs.items():
            for e in entries:
                group_key = php.to_string(e["CO6"])
                if not php.empty(e["UNIQUE_WORK_ID"]):
                    group_key = php.to_string(e["CO6"]) + "|" + php.to_string(e["UNIQUE_WORK_ID"])
                debit, credit = e["DEBIT"], e["CREDIT"]
                has_debit, has_credit = php.loose_ne_zero(debit), php.loose_ne_zero(credit)
                if has_credit and has_debit:
                    added = [debit, php.neg(credit)]
                    totals[key] = php.php_round(php.sub(php.add(totals[key], debit), credit), 2)
                elif has_debit:
                    added = [debit]
                    totals[key] = php.php_round(php.add(totals[key], debit), 2)
                elif has_credit:
                    added = [php.neg(credit)]
                    totals[key] = php.php_round(php.sub(totals[key], credit), 2)
                else:
                    added = None  # zero row: shows 0 once per head

                group = groups.get(group_key)
                if group is None:
                    group = groups[group_key] = {"entry": e, "values": {}}
                    group["values"][key] = added if added is not None else [0]
                elif added is not None:
                    group["values"].setdefault(key, []).extend(added)
                elif key not in group["values"]:
                    group["values"][key] = [0]

        table_rows = []
        total = 0
        for number, group in enumerate(groups.values(), start=1):
            e = group["entry"]
            sub_total = 0
            amounts = {}
            for k in heads:
                if k in group["values"]:
                    for value in group["values"][k]:
                        truncated = php.bcadd_scale2(value)
                        sub_total = php.add(sub_total, truncated)
                        amounts[k] = amounts.get(k, Decimal("0")) + Decimal(truncated)
            total = php.add(total, sub_total)
            # view.php: "handles the exponentially generated 0 value"
            if php.to_number(php.number_format(sub_total, 2)) == 0:
                sub_total = php.number_format(sub_total, 2)
            if php.to_number(php.number_format(total, 2)) == 0:
                total = php.number_format(total, 2)
            table_rows.append(DaybookRow(
                number=number, section=e["SECTION"], co6=e["CO6"], co7=e["CO7"], book_date=e["BOOK DATE"],
                party_name=e["PARTY NAME"], bill_desc=e["BILL DESC"], uwid=e["UNIQUE_WORK_ID"],
                is_jv=_contains(e["SECTION"], "JV"), values=group["values"],
                total_display=php.to_string(sub_total), amounts=amounts,
            ))
        tables.append(DaybookTable(code=code, heads=heads, rows=table_rows, head_totals=totals, total=total))
        month_total = php.add(month_total, total)

    return Daybook(heading=heading, allocation=day_book_for, tables=tables, month_total=month_total, warnings=warnings)
