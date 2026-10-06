"""Months ("2026-09") and financial years (FY 2026-27 = April 2026 to March 2027)."""
import calendar
import re
from datetime import date

_YM = re.compile(r"^(\d{4})-(0[1-9]|1[0-2])$")


def is_ym(value):
    return isinstance(value, str) and _YM.match(value) is not None


def parse_ym(value):
    m = _YM.match(value or "")
    if not m:
        raise ValueError("Invalid month %r (expected YYYY-MM)" % value)
    return int(m.group(1)), int(m.group(2))


def ym(year, month):
    return "%04d-%02d" % (year, month)


def previous_ym(value):
    y, m = parse_ym(value)
    return ym(y - 1, 12) if m == 1 else ym(y, m - 1)


def next_ym(value):
    y, m = parse_ym(value)
    return ym(y + 1, 1) if m == 12 else ym(y, m + 1)


def label(value):
    y, m = parse_ym(value)
    return "%s %d" % (calendar.month_name[m], y)


def short_label(value):
    y, m = parse_ym(value)
    return "%s %d" % (calendar.month_abbr[m], y)


def period(value):
    """Dates for the AIMS form (1/9/2026, 30/9/2026) and for display (01/09/2026, 30/09/2026)."""
    y, m = parse_ym(value)
    last = calendar.monthrange(y, m)[1]
    return {
        "start": "1/%d/%d" % (m, y),
        "end": "%d/%d/%d" % (last, m, y),
        "start_display": "01/%02d/%d" % (m, y),
        "end_display": "%02d/%02d/%d" % (last, m, y),
    }


def fy_start_year(value):
    y, m = parse_ym(value)
    return y if m >= 4 else y - 1


def fy_label(start_year):
    return "FY %d-%02d" % (start_year, (start_year + 1) % 100)


def fy_months(start_year):
    return [ym(start_year, m) for m in range(4, 13)] + [ym(start_year + 1, m) for m in range(1, 4)]


def is_fy_start(value):
    return parse_ym(value)[1] == 4


def default_month(today=None):
    """The Daybook is prepared early in a month for the month just closed."""
    today = today or date.today()
    return previous_ym(ym(today.year, today.month))
