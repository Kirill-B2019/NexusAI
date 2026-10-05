"""
Извлечение текста из документов разных форматов.
Поддержка: TXT, MD, PDF, DOCX, XLSX, XLS, CSV, ODS, код.
"""
import os
import io
import csv
import logging
from typing import Optional

logger = logging.getLogger("extractors")


def _sanitize(text: str) -> str:
    """
    Убирает null-байты и непечатаемые control-символы,
    которые PostgreSQL не принимает в TEXT.
    Сохраняет \n, \r, \t.
    """
    if not text:
        return text
    text = text.replace("\x00", "")
    text = "".join(
        c for c in text
        if c in ("\n", "\r", "\t") or ord(c) >= 32
    )
    return text


# ─── Текстовые ─────────────────────────────────────────────
def extract_txt(data: bytes) -> str:
    for enc in ("utf-8", "cp1251", "latin-1"):
        try:
            return data.decode(enc)
        except UnicodeDecodeError:
            continue
    return data.decode("utf-8", errors="replace")


def extract_md(data: bytes) -> str:
    return extract_txt(data)


def extract_code(data: bytes) -> str:
    return extract_txt(data)


# ─── PDF ───────────────────────────────────────────────────
def extract_pdf(data: bytes) -> str:
    try:
        import pdfplumber
    except ImportError:
        raise RuntimeError("pdfplumber не установлен")

    parts = []
    with pdfplumber.open(io.BytesIO(data)) as pdf:
        for page_num, page in enumerate(pdf.pages, 1):
            page_text = page.extract_text() or ""
            if page_text.strip():
                parts.append(f"--- Страница {page_num} ---\n{page_text}")
            for table in (page.extract_tables() or []):
                for row in table:
                    cells = [str(c or "").strip() for c in row]
                    parts.append(" | ".join(cells))
    return "\n\n".join(parts)


# ─── DOCX ──────────────────────────────────────────────────
def extract_docx(data: bytes) -> str:
    try:
        from docx import Document
    except ImportError:
        raise RuntimeError("python-docx не установлен")

    doc = Document(io.BytesIO(data))
    parts = []
    for para in doc.paragraphs:
        if para.text.strip():
            parts.append(para.text)
    for table in doc.tables:
        for row in table.rows:
            cells = [c.text.strip() for c in row.cells]
            parts.append(" | ".join(cells))
    return "\n".join(parts)


# ─── XLSX / XLSM ───────────────────────────────────────────
def extract_xlsx(data: bytes) -> str:
    try:
        import openpyxl
    except ImportError:
        raise RuntimeError("openpyxl не установлен")

    wb = openpyxl.load_workbook(io.BytesIO(data), data_only=True, read_only=True)
    parts = []
    for sheet_name in wb.sheetnames:
        ws = wb[sheet_name]
        parts.append(f"=== Лист: {sheet_name} ===")
        for row in ws.iter_rows(values_only=True):
            cells = [str(c) if c is not None else "" for c in row]
            if any(cells):
                parts.append(" | ".join(cells))
        parts.append("")
    wb.close()
    return "\n".join(parts)


# ─── XLS (старый формат) ───────────────────────────────────
def extract_xls(data: bytes) -> str:
    try:
        import xlrd
    except ImportError:
        raise RuntimeError("xlrd не установлен")

    wb = xlrd.open_workbook(file_contents=data)
    parts = []
    for sheet in wb.sheets():
        parts.append(f"=== Лист: {sheet.name} ===")
        for row_idx in range(sheet.nrows):
            row = sheet.row_values(row_idx)
            cells = [str(c) if c != "" else "" for c in row]
            if any(cells):
                parts.append(" | ".join(cells))
        parts.append("")
    return "\n".join(parts)


# ─── ODS (LibreOffice) ─────────────────────────────────────
def extract_ods(data: bytes) -> str:
    try:
        import pandas as pd
    except ImportError:
        raise RuntimeError("pandas не установлен")

    sheets = pd.read_excel(io.BytesIO(data), sheet_name=None, engine="odf")
    parts = []
    for name, df in sheets.items():
        parts.append(f"=== Лист: {name} ===")
        parts.append(df.fillna("").to_csv(index=False, sep=" | "))
        parts.append("")
    return "\n".join(parts)


# ─── CSV ───────────────────────────────────────────────────
def extract_csv(data: bytes) -> str:
    text = extract_txt(data)
    try:
        sample = text[:2048]
        dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
        reader = csv.reader(io.StringIO(text), dialect)
        return "\n".join(" | ".join(row) for row in reader if any(row))
    except Exception:
        return text


# ─── Диспетчер ─────────────────────────────────────────────
EXTRACTORS = {
    "text/plain": extract_txt,
    "text/markdown": extract_md,
    "text/x-markdown": extract_md,
    "text/csv": extract_csv,
    "application/pdf": extract_pdf,
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": extract_docx,
    "application/msword": extract_docx,
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": extract_xlsx,
    "application/vnd.ms-excel": extract_xls,
    "application/vnd.oasis.opendocument.spreadsheet": extract_ods,
    "text/x-python": extract_code,
    "text/x-javascript": extract_code,
    "text/x-typescript": extract_code,
    "application/json": extract_txt,
    "application/x-yaml": extract_txt,
    "text/yaml": extract_txt,
    "text/x-sh": extract_code,
}

EXT_TO_MIME = {
    ".txt": "text/plain",
    ".md": "text/markdown",
    ".markdown": "text/markdown",
    ".log": "text/plain",
    ".pdf": "application/pdf",
    ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    ".doc": "application/msword",
    ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    ".xlsm": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    ".xls": "application/vnd.ms-excel",
    ".ods": "application/vnd.oasis.opendocument.spreadsheet",
    ".csv": "text/csv",
    ".py": "text/x-python",
    ".js": "text/x-javascript",
    ".ts": "text/x-typescript",
    ".json": "application/json",
    ".yaml": "text/yaml",
    ".yml": "text/yaml",
    ".sh": "text/x-sh",
    ".sql": "text/plain",
    ".html": "text/plain",
    ".css": "text/plain",
    ".go": "text/x-python",
    ".rs": "text/x-python",
    ".java": "text/x-python",
    ".c": "text/x-python",
    ".cpp": "text/x-python",
    ".h": "text/x-python",
    ".xml": "text/plain",
}

SUPPORTED_EXTENSIONS = list(EXT_TO_MIME.keys())
MAX_FILE_SIZE = 50 * 1024 * 1024


def get_mime(filename: str, declared_mime: Optional[str] = None) -> str:
    ext = os.path.splitext(filename.lower())[1]
    return EXT_TO_MIME.get(ext, declared_mime or "text/plain")


def extract(filename: str, data: bytes, declared_mime: Optional[str] = None) -> str:
    if len(data) > MAX_FILE_SIZE:
        raise ValueError(f"Файл больше {MAX_FILE_SIZE // (1024*1024)} МБ")

    mime = get_mime(filename, declared_mime)
    extractor = EXTRACTORS.get(mime)

    if not extractor:
        logger.warning(f"Неизвестный mime '{mime}', пробуем как текст")
        return _sanitize(extract_txt(data)).strip()

    try:
        text = extractor(data)
    except Exception as e:
        logger.exception(f"Ошибка извлечения из {filename}: {e}")
        raise ValueError(f"Не удалось извлечь текст: {e}")

    return _sanitize(text).strip()
