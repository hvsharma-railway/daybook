"""roles, permissions and reporting views

Revision ID: 0002
Revises: 0001
"""
from alembic import op
import sqlalchemy as sa

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None

PERMISSIONS = {
    "process.run": "Run the monthly process: AIMS downloads, retries, restarts, opening balances",
    "reports.view": "View all months, allocations, sub-allocations, UWIDs and summaries; export them",
    "uwid.view": "View UWID-wise data (month-wise and overall)",
    "users.manage": "Create users and assign roles",
}
ROLES = {
    "admin": ("Administrator: everything", list(PERMISSIONS)),
    "accounts": ("Accounts: runs the monthly Daybook and sees all reports", ["process.run", "reports.view", "uwid.view"]),
    "uwid_viewer": ("UWID Viewer: read-only UWID data, month-wise and overall", ["uwid.view"]),
}


def upgrade():
    conn = op.get_bind()
    for name, description in PERMISSIONS.items():
        conn.execute(sa.text("INSERT INTO permissions (name, description) VALUES (:n, :d)"), {"n": name, "d": description})
    for name, (description, permissions) in ROLES.items():
        conn.execute(sa.text("INSERT INTO roles (name, description) VALUES (:n, :d)"), {"n": name, "d": description})
        for permission in permissions:
            conn.execute(sa.text(
                "INSERT INTO role_permissions (role_id, permission_id) "
                "SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = :r AND p.name = :p"), {"r": name, "p": permission})

    op.execute("""
        CREATE OR REPLACE VIEW v_uwid_balances AS
        SELECT b.ym, b.fy, b.allocation, b.code, b.head, b.uwid, m.name AS uwid_name,
               b.last_month_fy, b.for_month, b.to_month_fy, b.opening_running, b.closing_running
          FROM balance_uwids b LEFT JOIN uwid_master m ON m.uwid = b.uwid""")
    op.execute("""
        CREATE OR REPLACE VIEW v_month_allocation AS
        SELECT ym, fy, allocation, SUM(last_month_fy) AS last_month_fy, SUM(for_month) AS for_month,
               SUM(to_month_fy) AS to_month_fy, SUM(opening_running) AS opening_running,
               SUM(closing_running) AS closing_running
          FROM balance_sub_allocations GROUP BY ym, fy, allocation""")
    op.execute("""
        CREATE OR REPLACE VIEW v_fy_sub_allocation AS
        SELECT fy, allocation, code, SUM(for_month) AS fy_for_month, COUNT(*) AS months
          FROM balance_sub_allocations GROUP BY fy, allocation, code""")


def downgrade():
    for view in ("v_fy_sub_allocation", "v_month_allocation", "v_uwid_balances"):
        op.execute("DROP VIEW IF EXISTS %s" % view)
    op.execute("DELETE FROM role_permissions")
    op.execute("DELETE FROM roles")
    op.execute("DELETE FROM permissions")
