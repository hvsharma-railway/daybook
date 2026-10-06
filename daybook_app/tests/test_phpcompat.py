"""phpcompat must behave exactly like PHP 7.4 (reference values from tools/php_fuzz.php)."""
import json
from pathlib import Path

import pytest

from daybook import phpcompat as php

GOLDEN = json.loads((Path(__file__).parent / "golden" / "php_fuzz.json").read_text())


@pytest.mark.parametrize("case", GOLDEN["strings"], ids=lambda c: repr(c["in"]))
def test_string_semantics(case):
    s = case["in"]
    negated = php.neg(s)
    assert php.to_string(negated) == case["neg"]
    assert ("integer" if isinstance(negated, int) else "double") == case["neg_type"]
    assert php.loose_ne_zero(s) == case["ne0"]
    assert php.bcadd_scale2(s) == case["bcadd"]
    assert php.to_string(php.add(0, s)) == case["add0"]
    assert php.to_string(php.php_round(s, 2)) == case["round2"]
    assert php.number_format(float(php.to_number(s)), 2) == case["nf2"]
    assert php.empty(s) == case["empty"]


@pytest.mark.parametrize("case", GOLDEN["floats"], ids=lambda c: c["in"])
def test_float_semantics(case):
    f = float(case["in"])
    assert php.to_string(f) == case["str"]
    rounded = php.php_round(f, 2)
    assert php.to_string(rounded) == case["round2"]
    assert "%.17g" % rounded == case["round2_repr"]
    assert php.number_format(f, 2) == case["nf2"]
    assert php.bcadd_scale2(f) == case["bcadd"]
