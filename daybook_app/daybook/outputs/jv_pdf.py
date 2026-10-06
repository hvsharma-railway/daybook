"""JV reports: all JV text files merged into one, and that text as a PDF.

The PDF is landscape A4 in Courier at 60% of the usual 10 pt (6 pt, 7.2 pt line
spacing), so AIMS's fixed-width columns line up. Each JV starts on a new page and form
feeds inside a report are kept as page breaks. Lines too long for the page are cut, not
wrapped, so columns never break; a warning says how many.
"""
import io
import re

from reportlab.pdfgen import canvas

WIDTH, HEIGHT = 841.89, 595.28
MARGIN = 28.0
FONT_SIZE = 10 * 0.6
LEADING = 12 * 0.6
CHAR_WIDTH = FONT_SIZE * 0.6   # Courier advance width
MAX_CHARS = int((WIDTH - 2 * MARGIN) // CHAR_WIDTH)
LINES_PER_PAGE = int((HEIGHT - 2 * MARGIN) // LEADING)
_CONTROL = re.compile(r"[\x00-\x08\x0B\x0E-\x1F\x7F]")


def decode(content):
    for encoding in ("utf-8", "cp1252"):
        try:
            return content.decode(encoding)
        except UnicodeDecodeError:
            continue
    return content.decode("latin-1")


def _clean(text):
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    return _CONTROL.sub("", text).rstrip("\n\f")


def merged_text(reports):
    """reports: [(jv number, content bytes)] -> one text, JVs separated by form feeds (CRLF line ends)."""
    return "\f\r\n".join(_clean(decode(content)).replace("\n", "\r\n") for _, content in reports) + "\r\n"


def text_pdf(text, title="JV Reports"):
    """Returns (pdf bytes, warnings)."""
    buffer = io.BytesIO()
    c = canvas.Canvas(buffer, pagesize=(WIDTH, HEIGHT))
    c.setTitle(title)
    clipped = 0
    pages = _clean(text).split("\f")
    for chunk in pages:
        lines = chunk.lstrip("\n").split("\n") if chunk.strip() else [""]
        for start in range(0, len(lines), LINES_PER_PAGE):
            c.setFont("Courier", FONT_SIZE)
            y = HEIGHT - MARGIN - FONT_SIZE
            for line in lines[start:start + LINES_PER_PAGE]:
                line = line.expandtabs(8)
                if len(line) > MAX_CHARS:
                    clipped += 1
                    line = line[:MAX_CHARS]
                c.drawString(MARGIN, y, line)
                y -= LEADING
            c.showPage()
    c.save()
    warnings = ["%d line(s) were wider than the page (%d characters) and were cut." % (clipped, MAX_CHARS)] if clipped else []
    return buffer.getvalue(), warnings
