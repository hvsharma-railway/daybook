"""Jinja environment shared by the web pages and the PDF exports."""
from decimal import Decimal, InvalidOperation
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape

from .. import periods
from ..labels import head_label

TEMPLATES = Path(__file__).parent / "templates"


def money(value):
    """Indian grouping, 2 decimals: 12,34,567.89. Blank for None."""
    if value is None or value == "":
        return ""
    try:
        d = Decimal(str(value)).quantize(Decimal("0.01"))
    except InvalidOperation:
        return str(value)
    sign = "-" if d < 0 else ""
    whole, frac = ("%.2f" % abs(d)).split(".")
    if len(whole) > 3:
        head, tail = whole[:-3], whole[-3:]
        groups = []
        while len(head) > 2:
            groups.insert(0, head[-2:])
            head = head[:-2]
        if head:
            groups.insert(0, head)
        whole = ",".join(groups + [tail])
    return "%s%s.%s" % (sign, whole, frac)


def is_negative(value):
    try:
        return value is not None and value != "" and Decimal(str(value)) < 0
    except InvalidOperation:
        return False


env = Environment(loader=FileSystemLoader(str(TEMPLATES)), autoescape=select_autoescape(["html"]))
env.filters["money"] = money
env.filters["negative"] = is_negative
env.filters["ym_label"] = periods.label
env.filters["ym_short"] = periods.short_label
env.filters["head_label"] = head_label
env.globals["fy_label"] = periods.fy_label


def render(name, **context):
    return env.get_template(name).render(**context)
