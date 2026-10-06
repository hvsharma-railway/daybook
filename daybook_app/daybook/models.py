"""Database tables.

Months hold the nine Suspense Head reports (raw files, converted rows and every entry),
the JV / allocation-sheet documents fetched from AIMS, generated outputs and balances.
Users, roles and permissions provide role-based access.
"""
from datetime import datetime
from decimal import Decimal
from typing import Optional

from sqlalchemy import (JSON, Boolean, DateTime, ForeignKey, Index, Integer, LargeBinary, Numeric, String, Text,
                        UniqueConstraint, func)
from sqlalchemy.dialects.mysql import LONGBLOB, LONGTEXT
from sqlalchemy.orm import DeclarativeBase, Mapped, deferred, mapped_column, relationship

Money = Numeric(20, 2)
Blob = LargeBinary().with_variant(LONGBLOB(), "mysql")
LongText = Text().with_variant(LONGTEXT(), "mysql")


class Base(DeclarativeBase):
    pass


def _now():
    return mapped_column(DateTime, server_default=func.now(), nullable=False)


# ---- Monthly process -------------------------------------------------------------

class Month(Base):
    __tablename__ = "months"
    id: Mapped[int] = mapped_column(primary_key=True)
    ym: Mapped[str] = mapped_column(String(7), unique=True)          # 2026-09
    au: Mapped[str] = mapped_column(String(10))
    reports_ready_at: Mapped[Optional[datetime]] = mapped_column(DateTime)   # all nine reports in
    separated_at: Mapped[Optional[datetime]] = mapped_column(DateTime)       # JV separation done
    documents_ready_at: Mapped[Optional[datetime]] = mapped_column(DateTime) # all JVs + sheets in
    completed_at: Mapped[Optional[datetime]] = mapped_column(DateTime)       # outputs + balances done
    last_error: Mapped[Optional[str]] = mapped_column(Text)
    created_at: Mapped[datetime] = _now()
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now(), nullable=False)

    allocations: Mapped[list["MonthAllocation"]] = relationship(back_populates="month", order_by="MonthAllocation.position")


class MonthAllocation(Base):
    """One of the nine Suspense Head reports of a month."""
    __tablename__ = "month_allocations"
    __table_args__ = (UniqueConstraint("month_id", "allocation"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    month_id: Mapped[int] = mapped_column(ForeignKey("months.id", ondelete="CASCADE"))
    allocation: Mapped[str] = mapped_column(String(2))
    position: Mapped[int] = mapped_column(Integer)                    # 1..9, file order
    status: Mapped[str] = mapped_column(String(20), default="pending")  # pending|downloading|downloaded|failed
    message: Mapped[Optional[str]] = mapped_column(Text)
    via: Mapped[Optional[str]] = mapped_column(String(10))            # aims|upload
    source_name: Mapped[Optional[str]] = mapped_column(String(255))
    file_format: Mapped[Optional[str]] = mapped_column(String(10))
    heads: Mapped[Optional[int]] = mapped_column(Integer)
    entries: Mapped[Optional[int]] = mapped_column(Integer)
    original_file_id: Mapped[Optional[int]] = mapped_column(ForeignKey("files.id", ondelete="SET NULL"))
    converted_file_id: Mapped[Optional[int]] = mapped_column(ForeignKey("files.id", ondelete="SET NULL"))
    rows: Mapped[Optional[list]] = deferred(mapped_column(JSON))      # the report as read (cells as text)
    warnings: Mapped[Optional[list]] = mapped_column(JSON)
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now(), nullable=False)

    month: Mapped[Month] = relationship(back_populates="allocations")


class StoredFile(Base):
    __tablename__ = "files"
    __table_args__ = (Index("ix_files_month_kind", "month_id", "kind", "reference"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    month_id: Mapped[Optional[int]] = mapped_column(ForeignKey("months.id", ondelete="CASCADE"))
    # report_original | report_converted | jv_report | allocation_sheet | jv_reports_txt | jv_reports_pdf
    # | allocation_sheets_pdf | daybook_excel | daybook_pdf
    kind: Mapped[str] = mapped_column(String(30))
    reference: Mapped[Optional[str]] = mapped_column(String(50))      # allocation / JV / CO6 number
    file_name: Mapped[str] = mapped_column(String(255))
    mime_type: Mapped[str] = mapped_column(String(100))
    size_bytes: Mapped[int] = mapped_column(Integer)
    sha256: Mapped[str] = mapped_column(String(64))
    content: Mapped[bytes] = deferred(mapped_column(Blob))
    created_at: Mapped[datetime] = _now()


class SuspenseEntry(Base):
    """Every transaction row of the month's Suspense Head reports."""
    __tablename__ = "suspense_entries"
    __table_args__ = (
        Index("ix_entries_month_alloc", "month_id", "allocation", "code"),
        Index("ix_entries_uwid", "uwid"),
        Index("ix_entries_co6", "co6"),
    )
    id: Mapped[int] = mapped_column(primary_key=True)
    month_id: Mapped[int] = mapped_column(ForeignKey("months.id", ondelete="CASCADE"))
    allocation: Mapped[str] = mapped_column(String(2))
    code: Mapped[str] = mapped_column(String(4))          # sub-allocation, e.g. 2016
    head: Mapped[str] = mapped_column(String(8))          # e.g. 20164103
    row_no: Mapped[int] = mapped_column(Integer)
    section: Mapped[Optional[str]] = mapped_column(String(50))
    co6: Mapped[Optional[str]] = mapped_column(String(50))
    co7: Mapped[Optional[str]] = mapped_column(String(50))
    book_date: Mapped[Optional[str]] = mapped_column(String(20))
    party_name: Mapped[Optional[str]] = mapped_column(Text)
    bill_desc: Mapped[Optional[str]] = mapped_column(Text)
    debit_text: Mapped[Optional[str]] = mapped_column(String(40))
    credit_text: Mapped[Optional[str]] = mapped_column(String(40))
    debit: Mapped[Optional[Decimal]] = mapped_column(Numeric(20, 4))
    credit: Mapped[Optional[Decimal]] = mapped_column(Numeric(20, 4))
    spu: Mapped[Optional[str]] = mapped_column(String(100))
    contract_id: Mapped[Optional[str]] = mapped_column(String(100))
    tan_number: Mapped[Optional[str]] = mapped_column(String(50))
    uwid: Mapped[Optional[str]] = mapped_column(String(50))
    is_jv: Mapped[bool] = mapped_column(Boolean, default=False)
    is_sys_generated: Mapped[bool] = mapped_column(Boolean, default=False)
    in_daybook: Mapped[bool] = mapped_column(Boolean, default=True)   # passes the Daybook's filters


class Co6Item(Base):
    """A JV report or allocation sheet to fetch from AIMS (from JV separation)."""
    __tablename__ = "co6_items"
    __table_args__ = (UniqueConstraint("month_id", "kind", "number"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    month_id: Mapped[int] = mapped_column(ForeignKey("months.id", ondelete="CASCADE"))
    kind: Mapped[str] = mapped_column(String(10))         # jv | sheet
    position: Mapped[int] = mapped_column(Integer)
    number: Mapped[str] = mapped_column(String(50))
    status: Mapped[str] = mapped_column(String(20), default="pending")
    message: Mapped[Optional[str]] = mapped_column(Text)
    file_id: Mapped[Optional[int]] = mapped_column(ForeignKey("files.id", ondelete="SET NULL"))
    pages: Mapped[Optional[int]] = mapped_column(Integer)
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now(), nullable=False)


class Job(Base):
    __tablename__ = "jobs"
    id: Mapped[int] = mapped_column(primary_key=True)
    month_id: Mapped[int] = mapped_column(ForeignKey("months.id", ondelete="CASCADE"))
    status: Mapped[str] = mapped_column(String(20), default="queued")  # queued|running|paused|done|failed
    stage: Mapped[Optional[str]] = mapped_column(String(30))
    message: Mapped[Optional[str]] = mapped_column(Text)
    user_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    created_at: Mapped[datetime] = _now()
    started_at: Mapped[Optional[datetime]] = mapped_column(DateTime)
    finished_at: Mapped[Optional[datetime]] = mapped_column(DateTime)


# ---- Balances --------------------------------------------------------------------

class OpeningBalance(Base):
    """Last Month figures typed in where no earlier month exists to carry from."""
    __tablename__ = "opening_balances"
    __table_args__ = (UniqueConstraint("ym", "code"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    ym: Mapped[str] = mapped_column(String(7))
    code: Mapped[str] = mapped_column(String(4))
    last_month_fy: Mapped[Decimal] = mapped_column(Money, default=0)
    opening_running: Mapped[Decimal] = mapped_column(Money, default=0)
    running_confirmed: Mapped[bool] = mapped_column(Boolean, default=False)
    entered_by: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now(), nullable=False)


class BalanceSubAllocation(Base):
    __tablename__ = "balance_sub_allocations"
    __table_args__ = (UniqueConstraint("ym", "code"), Index("ix_bsa_alloc", "allocation", "ym"))
    id: Mapped[int] = mapped_column(primary_key=True)
    ym: Mapped[str] = mapped_column(String(7))
    fy: Mapped[int] = mapped_column(Integer)              # FY start year
    allocation: Mapped[str] = mapped_column(String(2))
    code: Mapped[str] = mapped_column(String(4))
    last_month_fy: Mapped[Decimal] = mapped_column(Money)
    for_month: Mapped[Decimal] = mapped_column(Money)
    to_month_fy: Mapped[Decimal] = mapped_column(Money)
    opening_running: Mapped[Decimal] = mapped_column(Money)
    closing_running: Mapped[Decimal] = mapped_column(Money)
    opening_source: Mapped[str] = mapped_column(String(10))   # carried|manual|fy_reset|missing
    running_estimated: Mapped[bool] = mapped_column(Boolean, default=False)


class BalanceUwid(Base):
    __tablename__ = "balance_uwids"
    __table_args__ = (UniqueConstraint("ym", "head", "uwid"), Index("ix_bu_uwid", "uwid", "ym"), Index("ix_bu_code", "code", "ym"))
    id: Mapped[int] = mapped_column(primary_key=True)
    ym: Mapped[str] = mapped_column(String(7))
    fy: Mapped[int] = mapped_column(Integer)
    allocation: Mapped[str] = mapped_column(String(2))
    code: Mapped[str] = mapped_column(String(4))
    head: Mapped[str] = mapped_column(String(8))
    uwid: Mapped[str] = mapped_column(String(50))         # "" when the entry has no UWID
    last_month_fy: Mapped[Decimal] = mapped_column(Money)
    for_month: Mapped[Decimal] = mapped_column(Money)
    to_month_fy: Mapped[Decimal] = mapped_column(Money)
    opening_running: Mapped[Decimal] = mapped_column(Money)
    closing_running: Mapped[Decimal] = mapped_column(Money)


class UwidMaster(Base):
    __tablename__ = "uwid_master"
    uwid: Mapped[str] = mapped_column(String(50), primary_key=True)
    name: Mapped[Optional[str]] = mapped_column(String(255))
    description: Mapped[Optional[str]] = mapped_column(Text)


# ---- Users and access ------------------------------------------------------------

class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(primary_key=True)
    username: Mapped[str] = mapped_column(String(50), unique=True)
    full_name: Mapped[str] = mapped_column(String(100))
    password_hash: Mapped[str] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = _now()
    last_login_at: Mapped[Optional[datetime]] = mapped_column(DateTime)

    roles: Mapped[list["Role"]] = relationship(secondary="user_roles", lazy="selectin")
    scopes: Mapped[list["UserScope"]] = relationship(lazy="selectin", cascade="all, delete-orphan")


class Role(Base):
    __tablename__ = "roles"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(50), unique=True)
    description: Mapped[str] = mapped_column(String(255))
    permissions: Mapped[list["Permission"]] = relationship(secondary="role_permissions", lazy="selectin")


class Permission(Base):
    __tablename__ = "permissions"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(50), unique=True)
    description: Mapped[str] = mapped_column(String(255))


class RolePermission(Base):
    __tablename__ = "role_permissions"
    role_id: Mapped[int] = mapped_column(ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True)
    permission_id: Mapped[int] = mapped_column(ForeignKey("permissions.id", ondelete="CASCADE"), primary_key=True)


class UserRole(Base):
    __tablename__ = "user_roles"
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    role_id: Mapped[int] = mapped_column(ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True)


class UserScope(Base):
    """Optional data limit for a user, e.g. only certain UWIDs or allocations."""
    __tablename__ = "user_scopes"
    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    scope_type: Mapped[str] = mapped_column(String(20))   # uwid | allocation
    value: Mapped[str] = mapped_column(String(50))


class Event(Base):
    __tablename__ = "events"
    __table_args__ = (Index("ix_events_ym", "ym", "created_at"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    ym: Mapped[Optional[str]] = mapped_column(String(7))
    user_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    event: Mapped[str] = mapped_column(String(40))
    reference: Mapped[Optional[str]] = mapped_column(String(50))
    message: Mapped[Optional[str]] = mapped_column(Text)
    created_at: Mapped[datetime] = _now()
