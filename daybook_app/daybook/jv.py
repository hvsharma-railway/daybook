"""JV separation: port of jvSeparation.php (separateJVAndNonJVNumbers).

CO6 numbers of "NN-JV" sections are JVs (their JV reports are downloaded); other CO6
numbers, except "P<n>-" entries, get allocation sheets. Order and de-duplication match PHP.
"""
import re

from . import phpcompat as php

_JV_SECTION = re.compile(r"^\d{2}-JV\n?$")
_P_ENTRY = re.compile(r"P\d+-")


def _php_trim(value):
    return php.to_string(value).strip(" \t\n\r\0\x0B")


def _digits_unique(numbers):
    seen, result = set(), []
    for number in numbers:
        digits = re.sub(r"\D", "", number)
        if digits in ("", "0") or digits in seen:  # array_filter drops "" and "0"
            continue
        seen.add(digits)
        result.append(digits)
    return result


def separate(reports):
    """reports: row lists of the Suspense Head files, in file order (1, 2, ...).

    Returns (jv_numbers, allocation_sheet_numbers)."""
    jv, non_jv = [], []
    for rows in reports:
        for row in rows:
            cells = [_php_trim(v) for v in row] + ["", ""]
            if cells[0].upper() == "SECTION" and cells[1].upper() == "CO6 NUMBER":
                continue
            if _JV_SECTION.match(cells[0]) and not php.empty(cells[1]):
                jv.append(cells[1])
            elif not php.empty(cells[1]):
                non_jv.append(cells[1])
    non_jv = [n for n in non_jv if not _P_ENTRY.search(n)]
    return _digits_unique(jv), _digits_unique(non_jv)
