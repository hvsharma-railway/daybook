"""Daybook web application."""
from fastapi import Depends, FastAPI, Form, Request
from fastapi.exceptions import HTTPException
from fastapi.responses import HTMLResponse, RedirectResponse
from starlette.middleware.sessions import SessionMiddleware

from .. import config
from . import routes_admin, routes_process, routes_reports
from .auth import REPORTS_VIEW, LoginRequired, authenticate, check_csrf, csrf_token, get_db, permissions_of
from .common import page

app = FastAPI(title="Daybook", docs_url=None, redoc_url=None, openapi_url=None)
app.add_middleware(SessionMiddleware, secret_key=config.SECRET_KEY, session_cookie="daybook_session",
                   max_age=12 * 3600, same_site="lax", https_only=False)

app.include_router(routes_process.router)
app.include_router(routes_reports.router)
app.include_router(routes_admin.router)


@app.exception_handler(LoginRequired)
async def login_required(request: Request, exc: LoginRequired):
    if request.headers.get("hx-request"):
        return HTMLResponse("", headers={"HX-Redirect": "/login"})
    return RedirectResponse("/login?next=" + request.url.path, status_code=303)


@app.exception_handler(HTTPException)
async def http_error(request: Request, exc: HTTPException):
    return HTMLResponse(page(request, "error.html", status=exc.status_code, message=exc.detail).body, status_code=exc.status_code)


@app.get("/login")
def login_form(request: Request, next: str = "/"):
    if not config.AUTH_ENABLED:
        return RedirectResponse("/", status_code=303)
    return page(request, "login.html", next=next, error=None)


@app.post("/login", dependencies=[Depends(check_csrf)])
def login(request: Request, username: str = Form(...), password: str = Form(...), next: str = Form("/"), db=Depends(get_db)):
    user = authenticate(db, username.strip(), password)
    if user is None:
        return page(request, "login.html", next=next, error="Wrong username or password.", status_code=401)
    request.session.clear()
    request.session["user_id"] = user.id
    csrf_token(request)
    target = next if next.startswith("/") and not next.startswith("//") else "/"
    if target == "/" and REPORTS_VIEW not in permissions_of(user):
        target = "/uwids"
    return RedirectResponse(target, status_code=303)


@app.post("/logout", dependencies=[Depends(check_csrf)])
def logout(request: Request):
    request.session.clear()
    return RedirectResponse("/login", status_code=303)
