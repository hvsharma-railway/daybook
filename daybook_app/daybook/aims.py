"""AIMS requests, made exactly as the AIMS pages make them, with the user's session cookie.

AIMS has no API login. A logged-in user's Cookie header is pasted into Daybook, kept in
Redis for a limited time (never in MySQL, files or logs) and sent with each request.
"""
import re
import ssl
from urllib.parse import quote

import httpx

from . import config, periods


class AimsError(RuntimeError):
    pass


class AimsSessionError(AimsError):
    """The AIMS session is missing or expired; every further request would fail the same way."""


SESSION_HINT = "The AIMS session has probably expired - log in to AIMS again and paste a fresh cookie."


def normalize_cookie(raw):
    cookie = re.sub(r"^\s*cookie:\s*", "", (raw or "").strip(), flags=re.I)
    cookie = re.sub(r"\s*[\r\n]+\s*", "; ", cookie)
    if not cookie or "=" not in cookie:
        raise ValueError("That does not look like a cookie header (expected name=value; name2=value2).")
    if re.search(r"[\x00-\x1F\x7F]", cookie):
        raise ValueError("The cookie contains invalid characters.")
    return cookie


def _ssl_context():
    """AIMS only offers RSA key exchange (AES128-GCM-SHA256), which OpenSSL 3 refuses at its default
    security level 2. Level 1 allows it; the certificate and host name are still verified."""
    ctx = ssl.create_default_context()
    ctx.set_ciphers("DEFAULT:@SECLEVEL=1")
    if not config.AIMS_SSL_VERIFY:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx


def cookie_names(cookie):
    return [p.split("=", 1)[0].strip() for p in cookie.split(";") if p.strip()]


def _title(content):
    m = re.search(rb"<title[^>]*>(.*?)</title>", content[:8192], re.I | re.S)
    if not m:
        return ""
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", m.group(1).decode("utf-8", "replace"))).strip()[:80]


def _is_web_page(content):
    head = content[:8192]
    if head.startswith(b"\xD0\xCF\x11\xE0") or head.startswith(b"PK") or head.startswith(b"%PDF"):
        return False
    return re.search(rb"<(!doctype html|html|form|body)\b", head, re.I) is not None


def _filename(response, default):
    m = re.search(r"filename\*?=(?:UTF-8'')?\"?([^\";]+)\"?", response.headers.get("content-disposition", ""), re.I)
    return m.group(1).strip().split("/")[-1].split("\\")[-1] if m else default


class AimsClient:
    def __init__(self, cookie, user_agent=None, base_url=None):
        if not cookie:
            raise AimsSessionError("No AIMS session is set. Paste your AIMS session cookie first, or upload the files by hand.")
        self.base_url = (base_url or config.AIMS_BASE_URL).rstrip("/")
        self.http = httpx.Client(
            timeout=httpx.Timeout(config.AIMS_TIMEOUT, connect=30),
            verify=_ssl_context(),
            follow_redirects=False,
            headers={
                "Cookie": cookie,
                "User-Agent": user_agent or "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "en-IN,en-GB;q=0.9,en-US;q=0.8,en;q=0.7",
                "Origin": self.base_url,
                "Upgrade-Insecure-Requests": "1",
            },
        )

    def close(self):
        self.http.close()

    def _send(self, method, path, referer, body=None):
        headers = {"Referer": self.base_url + referer}
        if body is not None:
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        try:
            response = self.http.request(method, self.base_url + path, content=body, headers=headers)
        except httpx.HTTPError as e:
            raise AimsError("Could not reach AIMS: %s" % (str(e) or e.__class__.__name__))
        if 300 <= response.status_code < 400:
            where = response.headers.get("location")
            raise AimsSessionError("AIMS redirected the request%s. %s" % (" to " + where if where else "", SESSION_HINT))
        if response.status_code != 200:
            title = _title(response.content)
            raise AimsError("AIMS returned HTTP %d%s." % (response.status_code, ' ("%s")' % title if title else ""))
        if not response.content:
            raise AimsError("AIMS returned an empty response.")
        attachment = "attachment" in response.headers.get("content-disposition", "").lower()
        if not attachment and _is_web_page(response.content):
            title = _title(response.content)
            raise AimsSessionError("AIMS returned a web page%s instead of the file. %s" % (' ("%s")' % title if title else "", SESSION_HINT))
        return response

    def suspense_head(self, allocation, ym):
        """Suspense Head report of one allocation for a month: (file name, content)."""
        p = periods.period(ym)
        body = "&".join("%s=%s" % (k, quote(str(v), safe="")) for k, v in (
            ("STARTDATE", p["start"]), ("ENDDATE", p["end"]), ("cmbau", config.AIMS_AU), ("chkDtlSum", "D"),
            ("chkTextExcel", "E"), ("allocation", allocation), ("cmbVC", ""), ("cmbcashjv", "0")))
        response = self._send("POST", "/IPAS/BooksSuspensionHead", "/IPAS/BooksForms/Suspension.jsp", body)
        return _filename(response, "SuspenseHead.xls"), response.content

    def jv_report(self, jv_number, ym):
        """JV report text for one JV number (AIMS ignores month/year when a JV number is given)."""
        year, month = periods.parse_ym(ym)
        body = "RepType=1&txtjvnumber=%s&txtyear=%d&cmbmonth=%02d&type=R&cmbtypeofjv=ALL&cmbau=%s&type=R" % (
            quote(jv_number, safe=""), year, month, quote(config.AIMS_AU, safe=""))
        response = self._send("POST", "/IPAS/ACB_JVReportController", "/IPAS/acbooksForms/JVReport.jsp?type=R", body)
        if _is_web_page(response.content):
            raise AimsError("AIMS returned a web page instead of the JV report text.")
        return _filename(response, "JVReport.txt"), response.content

    def allocation_sheet(self, co6):
        """Allocation sheet PDF for one CO6 number (the download downloadAllocationSheets.php opens)."""
        response = self._send("GET", "/IPAS/downloadPDF?filetype=CO6&dfilename=%s" % quote(co6, safe=""),
                              "/IPAS/")
        if not response.content.startswith(b"%PDF"):
            title = _title(response.content)
            raise AimsError("AIMS did not return a PDF%s." % (' ("%s")' % title if title else ""))
        return response.content
