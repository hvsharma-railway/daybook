FROM python:3.12-slim

# WeasyPrint needs Pango; DejaVu gives consistent fonts in generated PDFs.
# qpdf and poppler-utils are used by the tests (PDF fixtures, rendering pages to check them).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b fonts-dejavu-core qpdf poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .
ENV PYTHONUNBUFFERED=1 PYTHONPATH=/app
EXPOSE 8000
CMD ["uvicorn", "daybook.web.app:app", "--host", "0.0.0.0", "--port", "8000"]
