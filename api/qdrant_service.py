"""
Работа с Qdrant: запись чанков, поиск, удаление.
"""
import os
import uuid
import logging
from typing import List, Dict, Optional
from qdrant_client import QdrantClient
from qdrant_client.models import (
    PointStruct, Filter, FieldCondition, MatchValue, MatchAny,
    FilterSelector, VectorParams, Distance,
)

logger = logging.getLogger("qdrant")

QDRANT_URL = os.getenv("QDRANT_URL", "http://nexus-qdrant:6333")
COLLECTION = "project_documents"
VECTOR_SIZE = 384

_client: Optional[QdrantClient] = None


def get_client() -> QdrantClient:
    global _client
    if _client is None:
        _client = QdrantClient(url=QDRANT_URL, timeout=60)
    return _client


def ensure_collection() -> None:
    client = get_client()
    collections = [c.name for c in client.get_collections().collections]
    if COLLECTION in collections:
        return

    client.create_collection(
        collection_name=COLLECTION,
        vectors_config=VectorParams(size=VECTOR_SIZE, distance=Distance.COSINE),
    )
    client.create_payload_index(COLLECTION, "project_id", "keyword")
    client.create_payload_index(COLLECTION, "document_id", "keyword")
    logger.info(f"Создана коллекция {COLLECTION}")


def upsert_chunks(
    project_id: str,
    document_id: str,
    chunks: List[Dict],
    vectors: List[List[float]],
) -> List[str]:
    if len(chunks) != len(vectors):
        raise ValueError("chunks и vectors должны быть одной длины")

    client = get_client()
    points = []
    point_ids = []

    for chunk, vec in zip(chunks, vectors):
        point_id = str(uuid.uuid4())
        point_ids.append(point_id)
        points.append(PointStruct(
            id=point_id,
            vector=vec,
            payload={
                "project_id": project_id,
                "document_id": document_id,
                "chunk_index": chunk["index"],
                "text": chunk["text"],
                "char_start": chunk.get("char_start"),
                "char_end": chunk.get("char_end"),
            },
        ))

    client.upsert(collection_name=COLLECTION, points=points)
    logger.info(f"Записано {len(points)} чанков (doc={document_id})")
    return point_ids


def search(
    project_id: str,
    query_vector: List[float],
    top_k: int = 4,
    score_threshold: float = 0.5,
    document_ids: Optional[List[str]] = None,
) -> List[Dict]:
    """
    Поиск top-k релевантных чанков в проекте.

    Если document_ids указан — ищем только среди этих документов.
    """
    client = get_client()

    must_conditions = [
        FieldCondition(key="project_id", match=MatchValue(value=project_id)),
    ]
    if document_ids:
        must_conditions.append(
            FieldCondition(key="document_id", match=MatchAny(any=document_ids))
        )

    response = client.query_points(
        collection_name=COLLECTION,
        query=query_vector,
        query_filter=Filter(must=must_conditions),
        limit=top_k,
        score_threshold=score_threshold,
    )

    out = []
    for r in response.points:
        payload = r.payload or {}
        out.append({
            "point_id": str(r.id),
            "score": r.score,
            "text": payload.get("text", ""),
            "document_id": payload.get("document_id"),
            "chunk_index": payload.get("chunk_index"),
            "char_start": payload.get("char_start"),
            "char_end": payload.get("char_end"),
        })
    return out


def delete_by_document(document_id: str) -> None:
    client = get_client()
    client.delete(
        collection_name=COLLECTION,
        points_selector=FilterSelector(
            filter=Filter(
                must=[FieldCondition(key="document_id", match=MatchValue(value=document_id))]
            )
        ),
    )
    logger.info(f"Удалены точки документа {document_id}")


def delete_by_project(project_id: str) -> None:
    client = get_client()
    client.delete(
        collection_name=COLLECTION,
        points_selector=FilterSelector(
            filter=Filter(
                must=[FieldCondition(key="project_id", match=MatchValue(value=project_id))]
            )
        ),
    )
    logger.info(f"Удалены точки проекта {project_id}")


def count_by_project(project_id: str) -> int:
    client = get_client()
    result = client.count(
        collection_name=COLLECTION,
        count_filter=Filter(
            must=[FieldCondition(key="project_id", match=MatchValue(value=project_id))]
        ),
    )
    return result.count
