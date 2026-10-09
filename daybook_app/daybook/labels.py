"""Names and renames used across the Daybook."""
from . import phpcompat as php

# Heads renamed when a Suspense Head report is read. The renamed head becomes its own
# sub-allocation (CRRM9907 -> code "CRRM", shown as "CR-RM") instead of a column of 29-31.
HEAD_RENAMES = {
    "29319907": "CRRM9907",
}

# Display names of heads, where they differ from the code (none at present)
HEAD_LABELS = {}

# What each allocation is called (also the Capital Schedule fund it is checked against)
ALLOCATION_NAMES = {
    "20": "Capital",
    "21": "DRF",
    "26": "RSF",
    "28": "Nirbhaya",
    "29": "RRSK",
    "23": "DF-I",
    "33": "DF-II",
    "43": "DF-III",
    "53": "DF-IV",
}


def head_label(head):
    head = "" if head is None else str(head)
    return HEAD_LABELS.get(head, head)


def allocation_name(allocation):
    return ALLOCATION_NAMES.get(str(allocation), "")


def rename_heads(rows):
    """Apply HEAD_RENAMES to the "ALLOCATION : 29319907-***" headings of a report (returns new rows)."""
    renamed = []
    for row in rows:
        first = row[0] if row else None
        if first is not None and "ALLOCATION " in first:
            head = php.substr(first, 13, 8)
            if head in HEAD_RENAMES:
                row = [first[:13] + HEAD_RENAMES[head] + first[21:]] + list(row[1:])
        renamed.append(row)
    return renamed
