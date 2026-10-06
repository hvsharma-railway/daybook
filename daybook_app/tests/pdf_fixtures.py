"""Sample allocation-sheet PDFs and JV report texts for tests and the mock AIMS."""
import io
import subprocess
import tempfile
from pathlib import Path

from reportlab.lib.pagesizes import A4, landscape
from reportlab.pdfgen import canvas


def sheet_pdf(co6, pages=1, size=A4, rotate=0):
    buffer = io.BytesIO()
    c = canvas.Canvas(buffer, pagesize=size)
    w, h = size
    for n in range(1, pages + 1):
        c.setLineWidth(3)
        c.rect(20, 20, w - 40, h - 40)
        c.setFont("Helvetica-Bold", 28)
        c.drawCentredString(w / 2, h - 90, "ALLOCATION SHEET")
        c.setFont("Helvetica", 20)
        c.drawCentredString(w / 2, h - 140, "CO6 %s" % co6)
        c.drawCentredString(w / 2, h / 2, "Page %d of %d" % (n, pages))
        if rotate:
            c._doc.Pages  # noqa: B018 - keep reportlab page tree initialised
        c.showPage()
    c.save()
    data = buffer.getvalue()
    if rotate:
        from pypdf import PdfReader, PdfWriter
        reader, writer = PdfReader(io.BytesIO(data)), PdfWriter()
        for page in reader.pages:
            page.rotate(rotate)
            writer.add_page(page)
        out = io.BytesIO()
        writer.write(out)
        data = out.getvalue()
    return data


def with_object_streams(pdf_bytes):
    """Re-save as PDF 1.5 with compressed object streams (what newer generators produce)."""
    with tempfile.TemporaryDirectory() as tmp:
        src, dst = Path(tmp) / "in.pdf", Path(tmp) / "out.pdf"
        src.write_bytes(pdf_bytes)
        subprocess.run(["qpdf", "--object-streams=generate", "--force-version=1.5", str(src), str(dst)], check=True)
        return dst.read_bytes()


def jv_text(number, lines=40, wide=False):
    rows = ["WESTERN RAILWAY                                  JOURNAL VOUCHER REPORT",
            "JV NUMBER : %s" % number, "-" * 130]
    for i in range(1, lines + 1):
        rows.append("%4d  %-12s  %-60s %15.2f %15.2f" % (i, "2016410%d" % (i % 9), "BEING THE AMOUNT TRANSFERRED LINE %d" % i, i * 10.5, 0))
    if wide:
        rows.append("X" * 300)
    return ("\r\n".join(rows) + "\r\n").encode("cp1252")
