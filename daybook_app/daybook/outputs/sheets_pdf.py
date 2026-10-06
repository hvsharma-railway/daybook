"""All allocation sheets in one PDF: landscape A4, two sheet pages side by side.

Each CO6 starts on a new landscape page, so a page never mixes two CO6s (an odd last
page leaves the right half blank). Pages are scaled to fit their half, keeping their
proportions, with the CO6 number and page n/N printed underneath.
"""
import io

from pypdf import PageObject, PdfReader, PdfWriter, Transformation
from reportlab.pdfgen import canvas

WIDTH, HEIGHT = 841.89, 595.28   # A4 landscape, points
MARGIN, GAP, FOOTER = 18.0, 18.0, 14.0


class SheetPdfError(ValueError):
    def __init__(self, number, message):
        super().__init__(message)
        self.number = number


def page_count(content):
    return len(PdfReader(io.BytesIO(content)).pages)


def _footer(labels):
    buffer = io.BytesIO()
    c = canvas.Canvas(buffer, pagesize=(WIDTH, HEIGHT))
    c.setFont("Helvetica", 7)
    half = (WIDTH - 2 * MARGIN - GAP) / 2
    for slot, text in enumerate(labels):
        c.drawCentredString(MARGIN + slot * (half + GAP) + half / 2, MARGIN, text)
    c.save()
    return PdfReader(io.BytesIO(buffer.getvalue())).pages[0]


def two_up(sheets):
    """sheets: [(co6, pdf bytes)] in order. Returns the combined PDF bytes."""
    writer = PdfWriter()
    half = (WIDTH - 2 * MARGIN - GAP) / 2
    usable_height = HEIGHT - 2 * MARGIN - FOOTER
    for number, content in sheets:
        try:
            pages = list(PdfReader(io.BytesIO(content)).pages)
        except Exception as e:
            raise SheetPdfError(number, "The allocation sheet PDF for %s cannot be read (%s)." % (number, e))
        if not pages:
            raise SheetPdfError(number, "The allocation sheet PDF for %s has no pages." % number)
        total = len(pages)
        for start in range(0, total, 2):
            sheet = PageObject.create_blank_page(width=WIDTH, height=HEIGHT)
            labels = []
            for slot, page in enumerate(pages[start:start + 2]):
                try:
                    page.transfer_rotation_to_content()
                    box = page.cropbox
                    x0, y0 = float(box.left), float(box.bottom)
                    w, h = float(box.width), float(box.height)
                    scale = min(half / w, usable_height / h)
                    left = MARGIN + slot * (half + GAP) + (half - w * scale) / 2
                    bottom = MARGIN + FOOTER + (usable_height - h * scale) / 2
                    sheet.merge_transformed_page(page, Transformation().translate(-x0, -y0).scale(scale, scale).translate(left, bottom))
                except Exception as e:
                    raise SheetPdfError(number, "Page %d of the allocation sheet for %s cannot be placed (%s)." % (start + slot + 1, number, e))
                labels.append("CO6 %s  -  page %d of %d" % (number, start + slot + 1, total))
            sheet.merge_page(_footer(labels))
            writer.add_page(sheet)
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()
