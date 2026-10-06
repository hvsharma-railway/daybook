"""Login, permissions (RBAC) and CSRF protection.

Every route declares the permission it needs with `Depends(require("perm"))`.
Permissions come from the user's roles (tables roles / permissions / role_permissions).

While config.AUTH_ENABLED is off (the default for now) nobody logs in: every request acts
as one local user holding every permission.
"""
import secrets
from datetime import datetime

from argon2 import PasswordHasher
from argon2.exceptions import VerificationError
from fastapi import Depends, HTTPException, Request
from sqlalchemy import select

from .. import config
from ..db import SessionLocal
from ..models import User

PROCESS_RUN = "process.run"
REPORTS_VIEW = "reports.view"
UWID_VIEW = "uwid.view"
USERS_MANAGE = "users.manage"
ALL_PERMISSIONS = {PROCESS_RUN, REPORTS_VIEW, UWID_VIEW, USERS_MANAGE}


class LocalUser:
    """The single user while login is switched off."""
    id = None
    username = "local"
    full_name = "Local user"
    is_active = True
    roles = []
    scopes = []

_hasher = PasswordHasher()


class LoginRequired(Exception):
    pass


def hash_password(password):
    return _hasher.hash(password)


def verify_password(password_hash, password):
    try:
        return _hasher.verify(password_hash, password)
    except VerificationError:
        return False


def get_db():
    session = SessionLocal()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def permissions_of(user):
    if isinstance(user, LocalUser):
        return set(ALL_PERMISSIONS)
    return {p.name for role in user.roles for p in role.permissions}


def authenticate(session, username, password):
    user = session.scalars(select(User).where(User.username == username)).first()
    if user and user.is_active and verify_password(user.password_hash, password):
        user.last_login_at = datetime.utcnow()
        return user
    return None


def current_user(request: Request, db=Depends(get_db)):
    if not config.AUTH_ENABLED:
        request.state.user = LocalUser()
        request.state.permissions = set(ALL_PERMISSIONS)
        return request.state.user
    user_id = request.session.get("user_id")
    user = db.get(User, user_id) if user_id else None
    if user is None or not user.is_active:
        raise LoginRequired()
    request.state.user = user
    request.state.permissions = permissions_of(user)
    return user


def require(*permissions):
    """Dependency: the user must hold at least one of `permissions`."""
    def check(request: Request, user=Depends(current_user)):
        if not request.state.permissions.intersection(permissions):
            raise HTTPException(status_code=403, detail="You do not have access to this page.")
        return user
    return check


def csrf_token(request: Request):
    token = request.session.get("csrf")
    if not token:
        token = request.session["csrf"] = secrets.token_hex(16)
    return token


async def check_csrf(request: Request):
    """Dependency for POST routes: token from the form field or the X-CSRF-Token header (HTMX)."""
    token = request.headers.get("x-csrf-token")
    if not token:
        form = await request.form()
        token = form.get("csrf_token")
    if not token or not secrets.compare_digest(str(token), request.session.get("csrf", "")):
        raise HTTPException(status_code=403, detail="This page has expired. Reload it and try again.")


def uwid_scope(user):
    """None = all UWIDs; otherwise {'uwid': set, 'allocation': set} limits for a scoped viewer."""
    if REPORTS_VIEW in permissions_of(user) or not user.scopes:
        return None
    scope = {"uwid": set(), "allocation": set()}
    for s in user.scopes:
        scope.setdefault(s.scope_type, set()).add(s.value)
    return scope
