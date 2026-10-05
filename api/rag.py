"""
RAG: поиск релевантных чанков и формирование контекста.
"""
import os
import logging
from typing import List, Dict, Optional

import embeddings_client
import qdrant_service

logger = logging.getLogger("rag")

DEFAULT_TOP_K = 4
DEFAULT_MIN_SCORE = 0.5
MAX_CONTEXT_CHARS = 6000


async def search_context(
    project_id: str,
    query: str,
    top_k: int = DEFAULT_TOP_K,
    min_score: float = DEFAULT_MIN_SCORE,
    document_ids: Optional[List[str]] = None,
) -> List[Dict]:
    """
    Ищет top-k релевантных чанков в проекте.
    Если document_ids указан — только среди этих документов.
    """
    if not project_id or not query.strip():
        return []

    try:
        query_vec = await embeddings_client.embed_query(query)
    except Exception as e:
        logger.warning(f"Embed query failed: {e}")
        return []

    try:
        results = qdrant_service.search(
            project_id=project_id,
            query_vector=query_vec,
            top_k=top_k,
            score_threshold=min_score,
            document_ids=document_ids,
        )
    except Exception as e:
        logger.warning(f"Qdrant search failed: {e}")
        return []

    return results


async def build_context(
    project_id: str,
    query: str,
    top_k: int = DEFAULT_TOP_K,
    min_score: float = DEFAULT_MIN_SCORE,
    document_ids: Optional[List[str]] = None,
    document_names: Optional[Dict[str, str]] = None,
) -> Optional[str]:
    """
    Формирует текстовый контекст для промпта.

    document_names: {document_id: original_filename} — для человекочитаемых ссылок.
    """
    results = await search_context(project_id, query, top_k, min_score, document_ids)
    if not results:
        return None

    document_names = document_names or {}
    parts = []
    total_len = 0
    used_docs = set()

    for i, r in enumerate(results, 1):
        doc_id = r.get("document_id", "unknown")
        text = r.get("text", "").strip()
        score = r.get("score", 0)

        if not text:
            continue

        # Человекочитаемое имя документа
        doc_label = document_names.get(doc_id, f"doc {doc_id[:8]}…")
        used_docs.add(doc_id)

        snippet = (
            f"[{i}] Источник: {doc_label} (релевантность {score:.2f})\n"
            f"{text}"
        )

        if total_len + len(snippet) > MAX_CONTEXT_CHARS:
            break

        parts.append(snippet)
        total_len += len(snippet)

    if not parts:
        return None

    # Формируем инструкции
    context = (
        "ИНСТРУКЦИЯ ПО ИСПОЛЬЗОВАНИЮ КОНТЕКСТА:\n"
        "Ниже — релевантные фрагменты из документов проекта. "
        "Каждый фрагмент помечен номером [N] и именем источника.\n"
        "\n"
        "ПРАВИЛА:\n"
        "1. Отвечай ТОЛЬКО на основе предоставленных фрагментов. "
        "Не выдумывай факты, которых нет в контексте.\n"
        "2. Если в контексте нет прямого ответа на вопрос — прямо скажи об этом.\n"
        "3. Каждый факт, взят из конкретного фрагмента, помечай ссылкой [N].\n"
        "4. Если фрагменты из РАЗНЫХ документов — различай их, "
        "не смешивай и указывай, из какого документа взят каждый факт.\n"
        "5. Если вопрос про конкретный документ, а в контексте есть только фрагменты "
        "из других — скажи, что данных по этому документу нет.\n"
        "\n"
        "=== ФРАГМЕНТЫ ===\n\n"
        + "\n\n---\n\n".join(parts)
        + "\n\n=== КОНЕЦ ФРАГМЕНТОВ ==="
    )

    return context


def extract_sources(results: List[Dict], document_names: Optional[Dict[str, str]] = None) -> List[Dict]:
    """Извлекает список источников для ответа API."""
    document_names = document_names or {}
    sources = []
    for i, r in enumerate(results, 1):
        doc_id = r.get("document_id")
        sources.append({
            "index": i,
            "document_id": doc_id,
            "document_name": document_names.get(doc_id, None),
            "chunk_index": r.get("chunk_index"),
            "score": round(r.get("score", 0), 3),
            "preview": (r.get("text") or "")[:200],
        })
    return sources
