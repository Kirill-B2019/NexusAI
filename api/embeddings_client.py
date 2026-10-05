"""
Клиент эмбеддинг-сервиса (nexus-embeddings).
"""
import os
import asyncio
import logging
import httpx
from typing import List, Optional

logger = logging.getLogger("embeddings")

EMBEDDINGS_URL = os.getenv("EMBEDDINGS_URL", "http://nexus-embeddings:8001")
BATCH_SIZE = 16
TIMEOUT = 120
MAX_RETRIES = 3


class EmbeddingsError(Exception):
    pass


async def _embed_batch(
    client: httpx.AsyncClient,
    texts: List[str],
    prefix: str,
) -> List[List[float]]:
    """Один запрос на батч с retry."""
    payload = {"texts": texts, "prefix": prefix}
    last_err: Optional[Exception] = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            r = await client.post(f"{EMBEDDINGS_URL}/embed", json=payload)
            r.raise_for_status()
            data = r.json()
            return data["embeddings"]
        except Exception as e:
            last_err = e
            logger.warning(f"Embed attempt {attempt}/{MAX_RETRIES} failed: {e}")
            if attempt < MAX_RETRIES:
                await asyncio.sleep(1.5 ** attempt)

    raise EmbeddingsError(f"Embed failed after {MAX_RETRIES}: {last_err}")


async def embed_texts(texts: List[str], prefix: str = "passage") -> List[List[float]]:
    """
    Эмбеддинги для списка текстов.
    prefix: "query" для запросов, "passage" для документов.
    Разбивает на батчи.
    """
    if not texts:
        return []

    results: List[List[float]] = []
    async with httpx.AsyncClient(timeout=TIMEOUT) as client:
        for i in range(0, len(texts), BATCH_SIZE):
            batch = texts[i:i + BATCH_SIZE]
            vectors = await _embed_batch(client, batch, prefix)
            if len(vectors) != len(batch):
                raise EmbeddingsError(
                    f"Ожидалось {len(batch)} векторов, получено {len(vectors)}"
                )
            results.extend(vectors)

    return results


async def embed_query(text: str) -> List[float]:
    """Эмбеддинг одного запроса."""
    vecs = await embed_texts([text], prefix="query")
    return vecs[0]


async def health() -> dict:
    """Проверка эмбеддинг-сервиса."""
    async with httpx.AsyncClient(timeout=10) as client:
        r = await client.get(f"{EMBEDDINGS_URL}/health")
        r.raise_for_status()
        return r.json()
