"""PHP 7.4 value semantics needed to reproduce view.php's output exactly.

view.php mixes strings, ints and floats the PHP way: values read from the sheet stay
strings, negated credits become int/float, totals are floats rounded with PHP's
round(), row totals add bcadd()-truncated strings, and everything is printed with
PHP's float-to-string conversion (14 significant digits). These helpers model that.

A "PHP value" here is a Python str, int, float or None.
"""
import math
import re

_LEADING_NUMBER = re.compile(r"^[ \t\n\r\v\f]*([+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?)")
_INT_STRING = re.compile(r"^[ \t\n\r\v\f]*[+-]?\d+$")
_NUMERIC_STRING = re.compile(r"^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$")
_INT64_MAX = 2**63 - 1
_INT64_MIN = -(2**63)


def to_number(value):
    """PHP 7.4 numeric conversion for arithmetic/comparison (leading-numeric, else 0)."""
    if value is None:
        return 0
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return value
    s = str(value)
    if _INT_STRING.match(s):
        n = int(s.strip())
        return n if _INT64_MIN <= n <= _INT64_MAX else float(n)
    m = _LEADING_NUMBER.match(s)
    if not m:
        return 0
    text = m.group(1)
    if re.fullmatch(r"[+-]?\d+", text):
        n = int(text)
        return n if _INT64_MIN <= n <= _INT64_MAX else float(n)
    return float(text)


def is_numeric_string(value):
    return isinstance(value, str) and _NUMERIC_STRING.match(value) is not None


def add(a, b):
    """PHP `a + b`."""
    x, y = to_number(a), to_number(b)
    if isinstance(x, int) and isinstance(y, int):
        r = x + y
        return r if _INT64_MIN <= r <= _INT64_MAX else float(r)
    return float(x) + float(y)


def sub(a, b):
    """PHP `a - b`."""
    x, y = to_number(a), to_number(b)
    if isinstance(x, int) and isinstance(y, int):
        r = x - y
        return r if _INT64_MIN <= r <= _INT64_MAX else float(r)
    return float(x) - float(y)


def neg(a):
    """PHP unary minus."""
    x = to_number(a)
    if isinstance(x, int):
        return -x if x != _INT64_MIN else float(-x)
    return -x


def loose_ne_zero(value):
    """PHP 7.4 `value != 0`."""
    return to_number(value) != 0


def empty(value):
    """PHP empty() for scalars."""
    return value is None or value == "" or value == "0" or value == 0 or value is False


# ---- round() --------------------------------------------------------------------

_POW10 = [10.0**i for i in range(23)]


def _intlog10abs(value):
    value = abs(value)
    if value < 1e-8 or value > 1e22:
        return int(math.floor(math.log10(value)))
    values = [1e-8, 1e-7, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1,
              1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7,
              1e8, 1e9, 1e10, 1e11, 1e12, 1e13, 1e14, 1e15,
              1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22]
    result = 15
    result = result - 8 if value < values[result] else result + 8
    result = result - 4 if value < values[result] else result + 4
    result = result - 2 if value < values[result] else result + 2
    result = result - 1 if value < values[result] else result + 1
    if value < values[result]:
        result -= 1
    return result - 8


def _intpow10(power):
    if power < 0 or power > 22:
        return math.pow(10.0, power)
    return _POW10[power]


def _round_helper(value):
    # PHP_ROUND_HALF_UP, with C floor()/ceil() float semantics (ceil keeps -0.0)
    if value >= 0.0:
        return float(math.floor(value + 0.5))
    result = float(math.ceil(value - 0.5))
    return result if result != 0.0 else -0.0


def _math_round(value, places):
    """PHP 7.4 _php_math_round(value, places, PHP_ROUND_HALF_UP)."""
    if not math.isfinite(value) or value == 0.0:
        return value
    precision_places = 14 - _intlog10abs(value)
    f1 = _intpow10(abs(places))
    if precision_places > places and precision_places - 15 < places:
        use_precision = precision_places
        f2 = _intpow10(abs(use_precision))
        tmp = value * f2 if use_precision >= 0 else value / f2
        tmp = _round_helper(tmp)
        use_precision = places - precision_places
        f2 = _intpow10(abs(use_precision))
        tmp = tmp / f2
    else:
        tmp = value * f1 if places >= 0 else value / f1
        if abs(tmp) >= 1e15:
            return value
    tmp = _round_helper(tmp)
    if abs(places) < 23:
        tmp = tmp / f1 if places > 0 else tmp * f1
    else:
        tmp = float("%15fe%d" % (tmp, -places))
    return tmp


def php_round(value, places=0):
    """PHP round($value, $places): always returns float."""
    x = to_number(value)
    if isinstance(x, int):
        if places >= 0:
            return float(x)
        return _math_round(float(x), places)
    return _math_round(x, places)


def number_format(value, decimals=2):
    """PHP number_format($value, $decimals) with default separators."""
    d = float(to_number(value))
    negative = d < 0
    if negative:
        d = -d
    d = _math_round(d, decimals)
    text = "%.*f" % (decimals, abs(d))  # PHP's printf never prints "-0.00" here
    if not text[0].isdigit():
        return text
    if negative and d == 0:
        negative = False
    integral, _, frac = text.partition(".")
    groups = []
    while len(integral) > 3:
        groups.insert(0, integral[-3:])
        integral = integral[:-3]
    groups.insert(0, integral)
    result = ",".join(groups) + ("." + frac if decimals else "")
    return ("-" if negative else "") + result


# ---- bcadd($value, '0', 2) -------------------------------------------------------

_BC_NUMBER = re.compile(r"^([+-]?)(\d*)(?:\.(\d*))?$")


def bcadd_scale2(value):
    """PHP 7.4 bcadd($value, '0', 2): truncates to 2 decimals; malformed input counts as 0."""
    s = to_string(value)
    m = _BC_NUMBER.match(s)
    if not m or (m.group(2) == "" and not m.group(3)):
        return "0.00"
    sign, integral, frac = m.group(1), m.group(2) or "0", (m.group(3) or "")
    integral = integral.lstrip("0") or "0"
    frac = (frac + "00")[:2]
    if integral == "0" and frac == "00":
        sign = ""
    return ("-" if sign == "-" else "") + integral + "." + frac


# ---- string conversion / echo ----------------------------------------------------

def _float_to_string(x):
    """PHP 7.4 (string)$float with precision=14."""
    if math.isnan(x):
        return "NAN"
    if math.isinf(x):
        return "INF" if x > 0 else "-INF"
    if x == 0.0:
        return "-0" if math.copysign(1.0, x) < 0 else "0"
    text = "%.14G" % x
    if "E" in text:
        mantissa, exponent = text.split("E")
        if "." not in mantissa:
            mantissa += ".0"
        exp = int(exponent)
        return "%sE%s%d" % (mantissa, "+" if exp >= 0 else "-", abs(exp))
    return text


def to_string(value):
    """PHP string conversion / echo."""
    if value is None or value is False:
        return ""
    if value is True:
        return "1"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return _float_to_string(value)
    return str(value)


def substr(value, start, length=None):
    """PHP 7.4 substr(); returns "" where PHP returns false (both echo as "")."""
    s = to_string(value)
    n = len(s)
    if start < 0:
        start = max(n + start, 0)
    if start > n:
        return ""
    if length is None:
        return s[start:]
    if length < 0:
        end = n + length
        return s[start:end] if end > start else ""
    return s[start:start + length]
