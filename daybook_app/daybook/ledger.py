"""Monthly balances: Last Month / For The Month / To The Month.

For The Month comes from the Daybook engine (the exact 2-decimal amounts behind each
printed row total), at sub-allocation level (e.g. 20-16) and at head + UWID level.

Financial-year figures (shown): April starts from 0; any other month's Last Month is the
previous month's To The Month. Running figures (stored for later use) carry forward
across years. Where no previous month exists, Last Month for a sub-allocation comes
from an opening balance entered by hand (it also seeds the running balance, marked
estimated until confirmed). UWID balances start from 0 in the first month processed.
"""
from collections import defaultdict
from decimal import Decimal

from sqlalchemy import delete, select

from . import periods
from .engine import build_daybook
from .models import BalanceSubAllocation, BalanceUwid, Month, MonthAllocation, OpeningBalance

ZERO = Decimal("0.00")


def month_amounts(session, month):
    """For The Month per sub-allocation code and per (code, head, uwid)."""
    codes = defaultdict(lambda: ZERO)
    uwids = defaultdict(lambda: ZERO)
    items = session.scalars(select(MonthAllocation).where(MonthAllocation.month_id == month.id)
                            .order_by(MonthAllocation.position)).all()
    for item in items:
        daybook = build_daybook(item.rows or [])
        for table in daybook.tables:
            codes[table.code] += table.amount
            for row in table.rows:
                for head, amount in row.amounts.items():
                    uwids[(table.code, head, row.uwid_text)] += amount
    return codes, uwids


def _ready_month(session, ym):
    return session.scalars(select(Month).where(Month.ym == ym, Month.reports_ready_at.is_not(None))).first()


def compute_month(session, ym):
    """(Re)compute one month's balances. Returns the codes whose opening balance is missing."""
    month = _ready_month(session, ym)
    if month is None:
        raise ValueError("%s has not got all its Suspense Head reports yet." % periods.label(ym))
    fy = periods.fy_start_year(ym)
    april = periods.is_fy_start(ym)
    prev_ym = periods.previous_ym(ym)
    has_prev = _ready_month(session, prev_ym) is not None

    prev_codes = {b.code: b for b in session.scalars(select(BalanceSubAllocation).where(BalanceSubAllocation.ym == prev_ym))} if has_prev else {}
    prev_uwids = {(b.code, b.head, b.uwid): b for b in session.scalars(select(BalanceUwid).where(BalanceUwid.ym == prev_ym))} if has_prev else {}
    manual = {o.code: o for o in session.scalars(select(OpeningBalance).where(OpeningBalance.ym == ym))}
    codes, uwids = month_amounts(session, month)

    def carries(b):
        return b.closing_running != 0 or (not april and b.to_month_fy != 0)

    session.execute(delete(BalanceSubAllocation).where(BalanceSubAllocation.ym == ym))
    session.execute(delete(BalanceUwid).where(BalanceUwid.ym == ym))

    missing = []
    all_codes = set(codes) | {c for c, b in prev_codes.items() if carries(b)} | set(manual)
    for code in sorted(all_codes):
        prev, opening = prev_codes.get(code), manual.get(code)
        if april:
            last_fy, source = ZERO, "fy_reset"
        elif has_prev:
            last_fy, source = (prev.to_month_fy if prev else ZERO), "carried"
        elif opening is not None:
            last_fy, source = opening.last_month_fy, "manual"
        else:
            last_fy, source = ZERO, "missing"
            missing.append(code)

        if has_prev:
            opening_running = prev.closing_running if prev else ZERO
            estimated = prev.running_estimated if prev else False
        elif opening is not None:
            opening_running = opening.opening_running if opening.running_confirmed else opening.last_month_fy
            estimated = not opening.running_confirmed
        else:
            opening_running, estimated = ZERO, True

        for_month = codes.get(code, ZERO)
        session.add(BalanceSubAllocation(
            ym=ym, fy=fy, allocation=code[:2], code=code, last_month_fy=last_fy, for_month=for_month,
            to_month_fy=last_fy + for_month, opening_running=opening_running,
            closing_running=opening_running + for_month, opening_source=source, running_estimated=estimated))

    all_uwids = set(uwids) | {k for k, b in prev_uwids.items() if carries(b)}
    for key in sorted(all_uwids):
        code, head, uwid = key
        prev = prev_uwids.get(key)
        last_fy = ZERO if (april or prev is None) else prev.to_month_fy
        opening_running = prev.closing_running if prev else ZERO
        for_month = uwids.get(key, ZERO)
        session.add(BalanceUwid(
            ym=ym, fy=fy, allocation=code[:2], code=code, head=head, uwid=uwid, last_month_fy=last_fy,
            for_month=for_month, to_month_fy=last_fy + for_month, opening_running=opening_running,
            closing_running=opening_running + for_month))
    session.flush()
    return missing


def recompute_from(session, ym):
    """Recompute `ym` and every later month that has its reports, in order (balances carry forward)."""
    later = session.scalars(select(Month.ym).where(Month.ym >= ym, Month.reports_ready_at.is_not(None)).order_by(Month.ym)).all()
    missing = {}
    for each in later:
        missing[each] = compute_month(session, each)
    return missing


def remove_month(session, ym):
    session.execute(delete(BalanceSubAllocation).where(BalanceSubAllocation.ym == ym))
    session.execute(delete(BalanceUwid).where(BalanceUwid.ym == ym))
    nxt = session.scalars(select(Month.ym).where(Month.ym > ym, Month.reports_ready_at.is_not(None)).order_by(Month.ym)).first()
    if nxt:
        recompute_from(session, nxt)
