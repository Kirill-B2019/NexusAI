"""Documents: upload, list, status, reindex, delete."""
import asyncpg
import os
import asyncio
import json
import logging
import re
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, Request, Query
from pydantic import BaseModel

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit
import extractors
import chunker
import embeddings_client
import qdrant_service

logger = logging.getLogger("documents")

DATABASE_URL = os.getenv("DATABASE_URL")
DOCUMENTS_DIR = os.getenv("DOCUMENTS_DIR", "/app/data/documents")

router = APIRouter(tags=["documents"])


# ─── Helpers ───────────────────────────────────────────────
def _safe_filename(name: str) -> str:
    """Санитайзинг имени файла."""
    name = os.path.basename(name)
    name = re.sub(r"[^\w\-\.]", "_", name)
    return name[:200] or "unnamed"


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


def _doc_to_dict(row) -> Dict:
    return {
        "id": str(row["id"]),
        "project_id": str(row["project_id"]),
        "filename": row["filename"],
        "original_filename": row["original_filename"],
        "file_size": row["file_size"],
        "mime_type": row["mime_type"],
        "status": row["status"],
        "chunks_count": row["chunks_count"],
        "error": row["error"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "processed_at": row["processed_at"].isoformat() if row["processed_at"] else None,
    }


# ─── Фоновая обработка ─────────────────────────────────────
async def process_document(
    doc_id: str,
    project_id: str,
    file_path: str,
    filename: str,
    mime_type: str,
):
    """
    Извлечение → чанкинг → эмбеддинги → Qdrant.
    Обновляет статус документа в БД.
    """
    conn = await _conn()
    try:
        await conn.execute(
            "UPDATE documents SET status = 'processing' WHERE id = $1",
            doc_id,
        )
    finally:
        await conn.close()

    try:
        # 1. Извлечение текста
        with open(file_path, "rb") as f:
            data = f.read()
        text = extractors.extract(filename, data, mime_type)
        if not text.strip():
            raise ValueError("Пустой текст после извлечения")

        # 2. Чанкинг
        chunks = chunker.chunk_text(text)
        if not chunks:
            raise ValueError("Не удалось разбить на чанки")
        logger.info(f"[{doc_id}] Получено чанков: {len(chunks)}")

        # 3. Эмбеддинги
        texts = [c["text"] for c in chunks]
        vectors = await embeddings_client.embed_texts(texts, prefix="passage")
        logger.info(f"[{doc_id}] Получено векторов: {len(vectors)}")

        # 4. Запись в Qdrant
        qdrant_service.ensure_collection()
        point_ids = qdrant_service.upsert_chunks(
            project_id=project_id,
            document_id=doc_id,
            chunks=chunks,
            vectors=vectors,
        )

        # 5. Запись чанков в БД
        conn = await _conn()
        try:
            for chunk, pid in zip(chunks, point_ids):
                preview = (chunk["text"] or "").replace("\x00", "")[:200]
                await conn.execute("""
                    INSERT INTO document_chunks
                        (document_id, chunk_index, qdrant_point_id, text_preview,
                         char_start, char_end)
                    VALUES ($1, $2, $3, $4, $5, $6)
                """, doc_id, chunk["index"], pid,
                    preview, chunk["char_start"], chunk["char_end"])

            await conn.execute("""
                UPDATE documents
                SET status = 'ready',
                    chunks_count = $1,
                    processed_at = NOW()
                WHERE id = $2
            """, len(chunks), doc_id)
        finally:
            await conn.close()

        logger.info(f"[{doc_id}] Готово: {len(chunks)} чанков")

    except Exception as e:
        logger.exception(f"[{doc_id}] Ошибка обработки: {e}")
        conn = await _conn()
        try:
            await conn.execute("""
                UPDATE documents
                SET status = 'failed',
                    error = $1,
                    processed_at = NOW()
                WHERE id = $2
            """, str(e)[:1000], doc_id)
        finally:
            await conn.close()


# ─── Загрузка ──────────────────────────────────────────────
@router.post("/projects/{project_id}/documents", status_code=201)
async def upload_document(
    project_id: str,
    file: UploadFile = File(...),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)

    # Проверка проекта
    conn = await _conn()
    try:
        exists = await conn.fetchval("SELECT 1 FROM projects WHERE id = $1", project_id)
        if not exists:
            raise HTTPException(404, "Project not found")
    finally:
        await conn.close()

    # Проверка размера
    content = await file.read()
    if len(content) > extractors.MAX_FILE_SIZE:
        raise HTTPException(
            413,
            f"Файл больше {extractors.MAX_FILE_SIZE // (1024*1024)} МБ"
        )

    original_filename = file.filename or "unnamed"
    ext = os.path.splitext(original_filename)[1].lower()
    if ext not in extractors.SUPPORTED_EXTENSIONS:
        raise HTTPException(
            400,
            f"Неподдерживаемый формат '{ext}'. Допустимые: "
            f"{', '.join(extractors.SUPPORTED_EXTENSIONS)}"
        )

    mime_type = extractors.get_mime(original_filename, file.content_type)

    # Готовим путь: /app/data/documents/{project_id}/{doc_id}_{safe_name}
    doc_id = str(uuid.uuid4())
    safe_name = _safe_filename(original_filename)
    project_dir = Path(DOCUMENTS_DIR) / project_id
    project_dir.mkdir(parents=True, exist_ok=True)
    file_path = project_dir / f"{doc_id}_{safe_name}"

    # Сохраняем файл
    with open(file_path, "wb") as f:
        f.write(content)

    # Запись в БД
    conn = await _conn()
    try:
        row = await conn.fetchrow("""
            INSERT INTO documents
                (id, project_id, filename, original_filename, file_size, mime_type,
                 status, uploaded_via_key_id)
            VALUES ($1, $2, $3, $4, $5, $6, 'pending', $7)
            RETURNING *
        """, doc_id, project_id, safe_name, original_filename,
            len(content), mime_type,
            ctx.api_key_id)
    finally:
        await conn.close()

    # Запускаем обработку в фоне
    asyncio.create_task(
        process_document(doc_id, project_id, str(file_path), original_filename, mime_type)
    )

    await write_audit(
        actor=ctx.actor, action="document.upload",
        resource_type="document", resource_id=doc_id,
        project_id=project_id, api_key_id=ctx.api_key_id,
        details={"filename": original_filename, "size": len(content), "mime": mime_type},
    )

    return _doc_to_dict(row)


# ─── Список ────────────────────────────────────────────────
@router.get("/projects/{project_id}/documents")
async def list_documents(
    project_id: str,
    status: Optional[str] = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)

    conn = await _conn()
    try:
        if status:
            rows = await conn.fetch("""
                SELECT * FROM documents
                WHERE project_id = $1 AND status = $2
                ORDER BY created_at DESC
                LIMIT $3 OFFSET $4
            """, project_id, status, limit, offset)
        else:
            rows = await conn.fetch("""
                SELECT * FROM documents
                WHERE project_id = $1
                ORDER BY created_at DESC
                LIMIT $2 OFFSET $3
            """, project_id, limit, offset)

        total = await conn.fetchval(
            "SELECT COUNT(*) FROM documents WHERE project_id = $1", project_id
        )
    finally:
        await conn.close()

    return {
        "documents": [_doc_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


# ─── Метаданные ────────────────────────────────────────────
@router.get("/documents/{doc_id}")
async def get_document(doc_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM documents WHERE id = $1", doc_id)
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Document not found")

    require_project_access(ctx, str(row["project_id"]))
    return _doc_to_dict(row)


# ─── Статус ────────────────────────────────────────────────
@router.get("/documents/{doc_id}/status")
async def get_status(doc_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("""
            SELECT id, project_id, status, chunks_count, error, processed_at
            FROM documents WHERE id = $1
        """, doc_id)
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Document not found")

    require_project_access(ctx, str(row["project_id"]))
    return {
        "id": str(row["id"]),
        "status": row["status"],
        "chunks_count": row["chunks_count"],
        "error": row["error"],
        "processed_at": row["processed_at"].isoformat() if row["processed_at"] else None,
    }


# ─── Переиндексация ────────────────────────────────────────
@router.post("/documents/{doc_id}/reindex")
async def reindex_document(doc_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM documents WHERE id = $1", doc_id)
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Document not found")

    require_project_access(ctx, str(row["project_id"]))

    # Удаляем старые чанки
    qdrant_service.delete_by_document(doc_id)

    conn = await _conn()
    try:
        await conn.execute("DELETE FROM document_chunks WHERE document_id = $1", doc_id)
        await conn.execute("""
            UPDATE documents
            SET status = 'pending', error = NULL, chunks_count = 0
            WHERE id = $1
        """, doc_id)
    finally:
        await conn.close()

    # Определяем путь
    project_dir = Path(DOCUMENTS_DIR) / str(row["project_id"])
    file_path = project_dir / f"{doc_id}_{row['filename']}"

    if not file_path.exists():
        raise HTTPException(410, "Исходный файл недоступен")

    asyncio.create_task(
        process_document(
            doc_id, str(row["project_id"]), str(file_path),
            row["original_filename"], row["mime_type"]
        )
    )

    await write_audit(
        actor=ctx.actor, action="document.reindex",
        resource_type="document", resource_id=doc_id,
        project_id=str(row["project_id"]),
    )

    return {"id": doc_id, "status": "pending", "message": "Переиндексация запущена"}


# ─── Удаление ──────────────────────────────────────────────
@router.delete("/documents/{doc_id}", status_code=204)
async def delete_document(doc_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM documents WHERE id = $1", doc_id)
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Document not found")

    require_project_access(ctx, str(row["project_id"]))

    # Удаляем векторы из Qdrant
    try:
        qdrant_service.delete_by_document(doc_id)
    except Exception as e:
        logger.warning(f"Qdrant delete failed for {doc_id}: {e}")

    # Удаляем файл
    project_dir = Path(DOCUMENTS_DIR) / str(row["project_id"])
    file_path = project_dir / f"{doc_id}_{row['filename']}"
    if file_path.exists():
        try:
            file_path.unlink()
        except Exception as e:
            logger.warning(f"File delete failed: {e}")

    # Удаляем из БД (каскадно удалятся chunks)
    conn = await _conn()
    try:
        await conn.execute("DELETE FROM documents WHERE id = $1", doc_id)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="document.delete",
        resource_type="document", resource_id=doc_id,
        project_id=str(row["project_id"]),
    )


# ─── Анализ конкретного документа ──────────────────────────
from pydantic import BaseModel as _BM, Field as _Field
from typing import Optional as _Opt, List as _List


class AskDocumentRequest(_BM):
    message: str = _Field(..., min_length=1, max_length=8000)
    expert: _Opt[str] = None
    thinking: bool = False
    rag_top_k: int = _Field(6, ge=1, le=10)
    rag_min_score: float = _Field(0.4, ge=0.0, le=1.0)


@router.post("/documents/{doc_id}/ask")
async def ask_document(
    doc_id: str,
    req: AskDocumentRequest,
    ctx: AuthContext = Depends(verify_api_key),
):
    """
    Задать вопрос по конкретному документу.
    RAG ищет только внутри этого документа.
    """
    # Импорты локально, чтобы избежать циклов
    import rag as _rag
    import experts_service as _es
    import orchestrator as _orch

    conn = await _conn()
    try:
        doc_row = await conn.fetchrow(
            "SELECT id, project_id, original_filename, status FROM documents WHERE id = $1",
            doc_id,
        )
    finally:
        await conn.close()

    if not doc_row:
        raise HTTPException(404, "Document not found")

    require_project_access(ctx, str(doc_row["project_id"]))

    if doc_row["status"] != "ready":
        raise HTTPException(
            409,
            f"Документ не готов к анализу (статус: {doc_row['status']})"
        )

    project_id = str(doc_row["project_id"])
    doc_name = doc_row["original_filename"]

    # RAG: только по этому документу
    rag_context = None
    sources = []
    try:
        results = await _rag.search_context(
            project_id=project_id,
            query=req.message,
            top_k=req.rag_top_k,
            min_score=req.rag_min_score,
            document_ids=[doc_id],
        )
        if results:
            rag_context = await _rag.build_context(
                project_id, req.message,
                top_k=req.rag_top_k,
                min_score=req.rag_min_score,
                document_ids=[doc_id],
                document_names={doc_id: doc_name},
            )
            sources = _rag.extract_sources(results, {doc_id: doc_name})
    except Exception as e:
        logger.warning(f"RAG error in ask_document: {e}")

    if not rag_context:
        return {
            "document_id": doc_id,
            "document_name": doc_name,
            "rag_used": False,
            "message": "Не найдено релевантных фрагментов в документе",
            "sources": [],
            "content": None,
        }

    # Выбор эксперта
    expert_key = req.expert
    prompts = await _es.get_prompts()
    if not expert_key or expert_key not in prompts:
        expert_key = "system_architect" if "system_architect" in prompts else list(prompts.keys())[0]

    max_tokens = _orch.SINGLE_THINKING_MAX_TOKENS if req.thinking else _orch.SINGLE_MAX_TOKENS

    results = await _orch.execute(
        [expert_key], prompts, req.message, req.thinking, max_tokens, rag_context
    )
    r = results[0]

    if not r.get("ok"):
        raise HTTPException(502, f"Expert error: {r.get('error')}")

    await write_audit(
        actor=ctx.actor, action="document.ask",
        resource_type="document", resource_id=doc_id,
        project_id=project_id,
        details={"rag_sources": len(sources), "expert": expert_key},
    )

    return {
        "document_id": doc_id,
        "document_name": doc_name,
        "expert": expert_key,
        "rag_used": True,
        "sources": sources,
        "content": r["content"],
        "reasoning": r.get("reasoning", ""),
        "timings": r.get("timings", {}),
    }
