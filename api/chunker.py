"""
Разбиение текста на чанки с перекрытием.
"""
from typing import List, Dict

CHUNK_SIZE = 500
CHUNK_OVERLAP = 100
MIN_CHUNK_SIZE = 50
MAX_CHUNKS_PER_DOC = 5000


def chunk_text(
    text: str,
    chunk_size: int = CHUNK_SIZE,
    overlap: int = CHUNK_OVERLAP,
    min_size: int = MIN_CHUNK_SIZE,
    max_chunks: int = MAX_CHUNKS_PER_DOC,
) -> List[Dict]:
    """
    Разбивает текст на чанки с перекрытием.
    Возвращает: [{"index": 0, "text": "...", "char_start": 0, "char_end": 500}, ...]
    """
    text = text.strip()
    if not text:
        return []

    # Если весь текст короче минимального размера — один чанк
    if len(text) < min_size:
        return [{
            "index": 0,
            "text": text,
            "char_start": 0,
            "char_end": len(text),
        }]

    chunks = []
    pos = 0
    idx = 0

    while pos < len(text) and idx < max_chunks:
        end = min(pos + chunk_size, len(text))

        # Аккуратный обрез по границам предложений/абзацев
        if end < len(text):
            search_start = max(end - 100, pos)
            best_cut = -1
            for marker in (".\n", ". ", "!\n", "! ", "?\n", "? ", "\n\n", "\n"):
                cut = text.rfind(marker, search_start, end)
                if cut > best_cut:
                    best_cut = cut + len(marker)
            if best_cut > pos:
                end = best_cut

        chunk = text[pos:end].strip()
        if len(chunk) >= min_size:
            chunks.append({
                "index": idx,
                "text": chunk,
                "char_start": pos,
                "char_end": end,
            })
            idx += 1

        if end >= len(text):
            break

        pos = end - overlap

    # Страховка: если ничего не собралось, но текст есть — один чанк
    if not chunks:
        return [{
            "index": 0,
            "text": text,
            "char_start": 0,
            "char_end": len(text),
        }]

    return chunks
