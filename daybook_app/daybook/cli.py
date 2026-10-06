"""Command line: python -m daybook.cli create-user <username> <full name> <role> (password asked)."""
import getpass
import sys

from sqlalchemy import select

from .db import session_scope
from .models import Role, User
from .web.auth import hash_password


def create_user(username, full_name, role_name, password=None):
    password = password or getpass.getpass("Password for %s: " % username)
    if len(password) < 8:
        sys.exit("Password must be at least 8 characters.")
    with session_scope() as s:
        role = s.scalars(select(Role).where(Role.name == role_name)).first()
        if role is None:
            sys.exit("Unknown role %s (admin, accounts, uwid_viewer)." % role_name)
        user = s.scalars(select(User).where(User.username == username)).first()
        if user is None:
            user = User(username=username, full_name=full_name, password_hash=hash_password(password))
            s.add(user)
        else:
            user.password_hash = hash_password(password)
        user.roles = [role]
    print("User %s saved with role %s." % (username, role_name))


if __name__ == "__main__":
    if len(sys.argv) < 5 or sys.argv[1] != "create-user":
        sys.exit("Usage: python -m daybook.cli create-user <username> <full name> <admin|accounts|uwid_viewer>")
    create_user(sys.argv[2], sys.argv[3], sys.argv[4])
