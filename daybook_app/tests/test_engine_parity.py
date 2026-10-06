"""The engine must print every Daybook cell exactly as view.php does (golden files from tools/make_golden.sh)."""
import json
import re

import pytest

from daybook import jv
from daybook.engine import build_daybook
from helpers import FIXTURES, GOLDEN, VIEW_CASES, engine_grid, fixture_rows, golden_grid


def _normalise(grid):
    # whitespace inside PHP's template between tags is layout only
    def clean(s):
        return re.sub(r"\s+", " ", s).strip()
    out = json.loads(json.dumps(grid))
    for t in out["tables"]:
        t["title"] = clean(t["title"])
        t["header"] = [clean(h) for h in t["header"]]
        t["rows"] = [[clean(c) for c in r] for r in t["rows"]]
    out["summary"]["title"] = clean(out["summary"]["title"])
    return out


@pytest.mark.parametrize("name", sorted(VIEW_CASES))
def test_daybook_matches_view_php(name):
    expected = _normalise(golden_grid((GOLDEN / "view" / (name + ".html")).read_text(encoding="utf-8")))
    actual = _normalise(engine_grid(build_daybook(fixture_rows(VIEW_CASES[name]))))
    assert actual["summary"] == expected["summary"]
    assert len(actual["tables"]) == len(expected["tables"])
    for a, e in zip(actual["tables"], expected["tables"]):
        assert a["title"] == e["title"]
        assert a["header"] == e["header"]
        assert len(a["rows"]) == len(e["rows"]), a["title"]
        for i, (ra, re_) in enumerate(zip(a["rows"], e["rows"])):
            assert ra == re_, "%s row %d" % (a["title"], i + 1)


def test_jv_separation_matches_php():
    reports = [fixture_rows(FIXTURES / "aug2026" / ("%d.xlsx" % n)) for n in range(1, 10)]
    expected = json.loads((GOLDEN / "jv_aug2026.json").read_text())
    assert jv.separate(reports) == (expected["jv"], expected["nonJv"])


def test_jv_separation_synthetic_matches_php():
    reports = [fixture_rows(FIXTURES / "synthetic" / f) for f in ("edge20.xlsx", "noise21.xlsx")]
    expected = json.loads((GOLDEN / "jv_synthetic.json").read_text())
    assert jv.separate(reports) == (expected["jv"], expected["nonJv"])
