"""Stand-in for AIMS, for trying the monthly process without a real AIMS session.

  docker compose run -d --name mockaims --no-deps app uvicorn tools.mock_aims:app --host 0.0.0.0 --port 8099
  AIMS_BASE_URL=http://mockaims:8099 docker compose up -d app worker

Same endpoints and the same odd responses as AIMS (text/plain + Content-Disposition).
Accepts any cookie containing WASJSESSIONID=good; anything else gets a login page.
MOCK_FAIL_ONCE=26,08180426001166 makes those allocations / numbers fail on their first request.
August 2026 is served as the real .xls files; other months reuse August relabelled (.xlsx).
"""
import io
import os
import sys
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, Response

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "tests"))
from pdf_fixtures import jv_text, sheet_pdf, with_object_streams  # noqa: E402

from daybook.suspense import read_report, write_xlsx  # noqa: E402

DATA = Path(__file__).parent / "mock_data"
FIXTURES = Path(__file__).resolve().parent.parent / "tests" / "fixtures" / "aug2026"
ORDER = ["20", "21", "26", "28", "29", "23", "33", "43", "53"]
fail_once = {x.strip() for x in os.environ.get("MOCK_FAIL_ONCE", "").split(",") if x.strip()}
requests_log = []

app = FastAPI()

LOGIN = '<!DOCTYPE html><html><head><title>AIMS :: Login</title></head><body><form action="login">User Id</form></body></html>'


def _logged_in(request):
    return "WASJSESSIONID=good" in request.headers.get("cookie", "")


def _attachment(content, name):
    return Response(content, media_type="text/plain", headers={"Content-Disposition": "attachment;filename=%s" % name})


def _should_fail(key):
    if key in fail_once:
        fail_once.discard(key)
        return True
    return False


@app.post("/IPAS/BooksSuspensionHead")
async def suspense_head(request: Request):
    form = await request.form()
    requests_log.append(("suspense", dict(form)))
    if not _logged_in(request):
        return HTMLResponse(LOGIN)
    allocation = form.get("allocation")
    if _should_fail(allocation):
        return HTMLResponse("<html><head><title>Error 500: java.lang.NullPointerException</title></head></html>", status_code=500)
    n = ORDER.index(allocation) + 1
    start, end = form.get("STARTDATE"), form.get("ENDDATE")
    if (start, end) == ("1/8/2026", "31/8/2026"):
        return _attachment((DATA / ("aug2026_%d.xls" % n)).read_bytes(), "SuspenseHead.xls")
    rows = read_report((FIXTURES / ("%d.xlsx" % n)).read_bytes()).rows
    rows[1][0] = "SUSPENSE HEAD   REPORT FROM %s TO %s" % (start, end)
    return _attachment(write_xlsx(rows), "SuspenseHead.xls")


@app.post("/IPAS/ACB_JVReportController")
async def jv_report(request: Request):
    form = await request.form()
    requests_log.append(("jv", dict(form)))
    if not _logged_in(request):
        return HTMLResponse(LOGIN)
    number = form.get("txtjvnumber")
    if _should_fail(number):
        return HTMLResponse("<html><head><title>Error 500</title></head></html>", status_code=500)
    return _attachment(jv_text(number, lines=20 + int(number[-2:]) % 90, wide=number.endswith("9")), "JVReport.txt")


@app.get("/IPAS/downloadPDF")
def allocation_sheet(request: Request, filetype: str, dfilename: str):
    requests_log.append(("sheet", dfilename))
    if not _logged_in(request):
        return HTMLResponse(LOGIN)
    if _should_fail(dfilename):
        return HTMLResponse("<html><head><title>File not found</title></head></html>", status_code=404)
    pdf = sheet_pdf(dfilename, pages=1 + int(dfilename[-1]) % 3)
    if dfilename.endswith("5"):
        pdf = with_object_streams(pdf)
    return Response(pdf, media_type="application/pdf", headers={"Content-Disposition": "inline;filename=%s.pdf" % dfilename})


@app.get("/log")
def log():
    return {"requests": len(requests_log), "last": requests_log[-5:]}
