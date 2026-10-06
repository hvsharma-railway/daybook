"""Files kept in the database (content + sha256), and the audit log."""
import hashlib

from sqlalchemy import delete, select

from .models import Event, StoredFile

MIME = {
    ".xls": "application/vnd.ms-excel",
    ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    ".pdf": "application/pdf",
    ".txt": "text/plain; charset=utf-8",
    ".zip": "application/zip",
    ".html": "text/html",
}


def mime_for(name):
    for ext, mime in MIME.items():
        if name.lower().endswith(ext):
            return mime
    return "application/octet-stream"


def store_file(session, month_id, kind, reference, file_name, content):
    """Save a file, replacing any earlier one with the same month, kind and reference."""
    session.execute(delete(StoredFile).where(StoredFile.month_id == month_id, StoredFile.kind == kind,
                                             StoredFile.reference == reference))
    stored = StoredFile(month_id=month_id, kind=kind, reference=reference, file_name=file_name,
                        mime_type=mime_for(file_name), size_bytes=len(content),
                        sha256=hashlib.sha256(content).hexdigest(), content=content)
    session.add(stored)
    session.flush()
    return stored


def find_file(session, month_id, kind, reference=None):
    query = select(StoredFile).where(StoredFile.month_id == month_id, StoredFile.kind == kind)
    if reference is not None:
        query = query.where(StoredFile.reference == reference)
    return session.scalars(query.order_by(StoredFile.id.desc())).first()


def log_event(session, event, ym=None, reference=None, message=None, user_id=None):
    session.add(Event(ym=ym, event=event, reference=reference, message=message, user_id=user_id))
