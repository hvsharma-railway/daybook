"""capital schedule on months

Revision ID: 0003
Revises: 0002
"""
from alembic import op
import sqlalchemy as sa

revision = "0003"
down_revision = "0002"
branch_labels = None
depends_on = None


def upgrade():
    op.add_column("months", sa.Column("capital_status", sa.String(20), nullable=False, server_default="pending"))
    op.add_column("months", sa.Column("capital_message", sa.Text(), nullable=True))
    op.add_column("months", sa.Column("capital_file_id", sa.Integer(), nullable=True))
    op.create_foreign_key("fk_months_capital_file", "months", "files", ["capital_file_id"], ["id"], ondelete="SET NULL")


def downgrade():
    op.drop_constraint("fk_months_capital_file", "months", type_="foreignkey")
    op.drop_column("months", "capital_file_id")
    op.drop_column("months", "capital_message")
    op.drop_column("months", "capital_status")
