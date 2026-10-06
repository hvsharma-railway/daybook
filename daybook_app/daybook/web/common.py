"""Helpers shared by the route modules."""
from urllib.parse import quote

from fastapi import HTTPException, Request
from fastapi.responses import HTMLResponse, Response

from .. import config, periods
from ..outputs import documents, excel
from .auth import PROCESS_RUN, REPORTS_VIEW, USERS_MANAGE, UWID_VIEW, csrf_token
from .templating import render


def page(request: Request, template, status_code=200, **context):
    user = getattr(request.state, "user", None)
    perms = getattr(request.state, "permissions", set())
    html = render(template, request=request, user=user, perms=perms, csrf=csrf_token(request), auth_enabled=config.AUTH_ENABLED,
                  PROCESS_RUN=PROCESS_RUN, REPORTS_VIEW=REPORTS_VIEW, UWID_VIEW=UWID_VIEW, USERS_MANAGE=USERS_MANAGE,
                  **context)
    return HTMLResponse(html, status_code=status_code)


def download(content, file_name, mime):
    return Response(content, media_type=mime, headers={
        "Content-Disposition": "attachment; filename*=UTF-8''%s" % quote(file_name), "Cache-Control": "no-store"})


XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def report_response(request, title, tables, fmt=None, subtitle="", template="report.html", **context):
    """Render a report page, or export it when ?format=xlsx|pdf."""
    safe = "".join(c if c.isalnum() or c in "-_ " else "_" for c in title).strip().replace(" ", "_")
    if fmt == "xlsx":
        return download(excel.table_workbook(title, tables), safe + ".xlsx", XLSX)
    if fmt == "pdf":
        return download(documents.report_pdf(title, tables, subtitle), safe + ".pdf", "application/pdf")
    return page(request, template, title=title, subtitle=subtitle, tables=tables, **context)


def require_ym(value):
    if not periods.is_ym(value):
        raise HTTPException(404, "Unknown month.")
    return value


def parse_fy(value, default):
    try:
        return int(value) if value not in (None, "") else default
    except ValueError:
        raise HTTPException(404, "Unknown financial year.")
