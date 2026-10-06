"""User management: create users, assign roles and optional UWID / allocation limits."""
from fastapi import APIRouter, Depends, Form, HTTPException, Request
from fastapi.responses import RedirectResponse
from sqlalchemy import select

from .. import config
from ..models import Event, Role, User, UserScope
from ..storage import log_event
from .auth import USERS_MANAGE, check_csrf, get_db, hash_password, require
from .common import page

router = APIRouter()
need_admin = require(USERS_MANAGE)


def _scopes(text, scope_type):
    return [v.strip() for v in (text or "").replace("\n", ",").split(",") if v.strip()]


def _auth_on():
    if not config.AUTH_ENABLED:
        raise HTTPException(404, "User management is switched off (login is not enabled yet).")


@router.get("/admin/users", dependencies=[Depends(_auth_on)])
def users(request: Request, user=Depends(need_admin), db=Depends(get_db)):
    events = db.scalars(select(Event).order_by(Event.id.desc()).limit(50)).all()
    return page(request, "admin_users.html", users=db.scalars(select(User).order_by(User.username)).all(),
                roles=db.scalars(select(Role).order_by(Role.id)).all(), events=events, error=None)


@router.post("/admin/users", dependencies=[Depends(_auth_on), Depends(check_csrf)])
async def save_user(request: Request, user=Depends(need_admin), db=Depends(get_db)):
    form = await request.form()
    user_id = form.get("user_id")
    username = (form.get("username") or "").strip()
    password = form.get("password") or ""
    target = db.get(User, int(user_id)) if user_id else None
    if target is None:
        if not username or len(password) < 8:
            raise HTTPException(400, "A new user needs a username and a password of at least 8 characters.")
        if db.scalars(select(User).where(User.username == username)).first():
            raise HTTPException(400, "Username %s is already taken." % username)
        target = User(username=username, full_name=form.get("full_name") or username, password_hash=hash_password(password))
        db.add(target)
    else:
        target.full_name = form.get("full_name") or target.full_name
        if password:
            if len(password) < 8:
                raise HTTPException(400, "Passwords need at least 8 characters.")
            target.password_hash = hash_password(password)
    target.is_active = form.get("is_active") == "on" or target.id == user.id
    role_ids = {int(r) for r in form.getlist("roles")}
    target.roles = db.scalars(select(Role).where(Role.id.in_(role_ids))).all() if role_ids else []
    target.scopes = [UserScope(scope_type="uwid", value=v) for v in _scopes(form.get("scope_uwids"), "uwid")] + \
                    [UserScope(scope_type="allocation", value=v) for v in _scopes(form.get("scope_allocations"), "allocation")]
    db.flush()
    log_event(db, "user_saved", reference=target.username, user_id=user.id)
    return RedirectResponse("/admin/users", status_code=303)
