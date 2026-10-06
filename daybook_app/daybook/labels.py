"""Display names for heads. Data stays under the AIMS code; only what is shown changes."""

# AIMS head code -> name used in the Daybook
HEAD_LABELS = {
    "29319907": "CRRM9907",
}


def head_label(head):
    head = "" if head is None else str(head)
    return HEAD_LABELS.get(head, head)
