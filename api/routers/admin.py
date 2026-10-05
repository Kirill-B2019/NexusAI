"""Admin: stats, usage, system health."""
import asyncpg
import os
import httpx
import time
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_admin
import experts_service
import orchestrator

DATABASE_URL = os.getenv("DATABASE_URL")
EMBEDDINGS_URL = os.getenv("EMBEDDINGS_URL", "http://nexus-embeddings:8001")
MODEL_URL = os.getenv("MODEL_SERVER_URL", "http://nexus-model:8080")
QDRANT_URL = os.getenv("QDRANT_URL", "http://nexus-qdrant:6333")

router = APIRouter(prefix="/admin", tags=["admin"])


class ExpertTestRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=8000)
    thinking: bool = False


def _require_admin(ctx: AuthContext):
    require_admin(ctx)


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


# ─── Общая статистика ──────────────────────────────────────
@router.get("/stats")
async def get_stats(ctx: AuthContext = Depends(verify_api_key)):
    _require_admin(ctx)
    conn = await _conn()
    try:
        projects = await conn.fetchval("SELECT COUNT(*) FROM projects")
        documents = await conn.fetchval("SELECT COUNT(*) FROM documents")
        documents_ready = await conn.fetchval(
            "SELECT COUNT(*) FROM documents WHERE status = 'ready'"
        )
        documents_failed = await conn.fetchval(
            "SELECT COUNT(*) FROM documents WHERE status = 'failed'"
        )
        chunks = await conn.fetchval(
            "SELECT COALESCE(SUM(chunks_count), 0) FROM documents"
        )
        conversations = await conn.fetchval("SELECT COUNT(*) FROM conversations")
        messages = await conn.fetchval("SELECT COUNT(*) FROM messages")
        decisions = await conn.fetchval("SELECT COUNT(*) FROM decisions")
        tasks = await conn.fetchval("SELECT COUNT(*) FROM tasks")
        api_keys_active = await conn.fetchval(
            "SELECT COUNT(*) FROM api_keys WHERE is_active = TRUE"
        )
        api_keys_total = await conn.fetchval("SELECT COUNT(*) FROM api_keys")

        experts_total = await conn.fetchval("SELECT COUNT(*) FROM experts WHERE deleted_at IS NULL")
        experts_enabled = await conn.fetchval(
            "SELECT COUNT(*) FROM experts WHERE is_enabled = TRUE AND deleted_at IS NULL"
        )

        # Аудит за последние 24 часа
        events_24h = await conn.fetchval(
            "SELECT COUNT(*) FROM audit_log WHERE created_at > NOW() - INTERVAL '24 hours'"
        )
        chat_24h = await conn.fetchval(
            "SELECT COUNT(*) FROM audit_log "
            "WHERE created_at > NOW() - INTERVAL '24 hours' "
            "AND action LIKE 'chat.%'"
        )
    finally:
        await conn.close()

    return {
        "projects": projects,
        "documents": {
            "total": documents,
            "ready": documents_ready,
            "failed": documents_failed,
        },
        "chunks": chunks,
        "conversations": conversations,
        "messages": messages,
        "decisions": decisions,
        "tasks": tasks,
        "api_keys": {
            "active": api_keys_active,
            "total": api_keys_total,
        },
        "experts": {
            "enabled": experts_enabled,
            "total": experts_total,
        },
        "audit_24h": {
            "total": events_24h,
            "chat_requests": chat_24h,
        },
    }


# ─── Расход по проектам ────────────────────────────────────
@router.get("/projects-usage")
async def get_projects_usage(
    limit: int = Query(50, ge=1, le=200),
    ctx: AuthContext = Depends(verify_api_key),
):
    _require_admin(ctx)
    conn = await _conn()
    try:
        rows = await conn.fetch("""
            SELECT
                p.id,
                p.name,
                p.external_id,
                (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id) AS documents_count,
                (SELECT COUNT(*) FROM conversations c WHERE c.project_id = p.id) AS conversations_count,
                (SELECT COUNT(*) FROM messages m
                    JOIN conversations c ON c.id = m.conversation_id
                    WHERE c.project_id = p.id) AS messages_count,
                (SELECT COUNT(*) FROM decisions d WHERE d.project_id = p.id) AS decisions_count,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS tasks_count,
                (SELECT COUNT(*) FROM api_keys k WHERE k.project_id = p.id AND k.is_active = TRUE) AS active_keys,
                (SELECT MAX(created_at) FROM audit_log a WHERE a.project_id = p.id) AS last_activity
            FROM projects p
            ORDER BY p.created_at DESC
            LIMIT $1
        """, limit)
    finally:
        await conn.close()

    return {
        "projects": [
            {
                "id": str(r["id"]),
                "name": r["name"],
                "external_id": r["external_id"],
                "documents_count": r["documents_count"],
                "conversations_count": r["conversations_count"],
                "messages_count": r["messages_count"],
                "decisions_count": r["decisions_count"],
                "tasks_count": r["tasks_count"],
                "active_keys": r["active_keys"],
                "last_activity": r["last_activity"].isoformat() if r["last_activity"] else None,
            }
            for r in rows
        ],
    }


# ─── System health ─────────────────────────────────────────
@router.get("/system-health")
async def system_health(ctx: AuthContext = Depends(verify_api_key)):
    _require_admin(ctx)
    result = {
        "status": "ok",
        "services": {},
        "timestamp": time.time(),
    }

    # API — всегда ok, потому что запрос дошёл сюда
    result["services"]["api"] = {"status": "ok"}

    # Model
    try:
        async with httpx.AsyncClient(timeout=5) as client:
            r = await client.get(f"{MODEL_URL}/health")
            result["services"]["model"] = {
                "status": "ok" if r.status_code == 200 else "error",
                "code": r.status_code,
            }
    except Exception as e:
        result["services"]["model"] = {"status": "error", "error": str(e)[:200]}
        result["status"] = "degraded"

    # Embeddings
    try:
        async with httpx.AsyncClient(timeout=5) as client:
            r = await client.get(f"{EMBEDDINGS_URL}/health")
            data = r.json()
            result["services"]["embeddings"] = {
                "status": "ok" if r.status_code == 200 else "error",
                "model": data.get("model"),
            }
    except Exception as e:
        result["services"]["embeddings"] = {"status": "error", "error": str(e)[:200]}
        result["status"] = "degraded"

    # PostgreSQL
    try:
        conn = await _conn()
        try:
            await conn.fetchval("SELECT 1")
            result["services"]["postgres"] = {"status": "ok"}
        finally:
            await conn.close()
    except Exception as e:
        result["services"]["postgres"] = {"status": "error", "error": str(e)[:200]}
        result["status"] = "degraded"

    # Qdrant
    try:
        async with httpx.AsyncClient(timeout=5) as client:
            r = await client.get(f"{QDRANT_URL}/readyz")
            result["services"]["qdrant"] = {
                "status": "ok" if r.status_code == 200 else "error",
                "code": r.status_code,
            }
    except Exception as e:
        result["services"]["qdrant"] = {"status": "error", "error": str(e)[:200]}
        result["status"] = "degraded"

    return result


# ─── Тестовый прогон эксперта ──────────────────────────────
@router.post("/experts/{expert_key}/test")
async def test_expert(
    expert_key: str,
    payload: ExpertTestRequest,
    ctx: AuthContext = Depends(verify_api_key),
):
    _require_admin(ctx)

    prompts = await experts_service.get_prompts()
    if expert_key not in prompts:
        raise HTTPException(404, f"Expert '{expert_key}' not found or disabled")

    start = time.time()
    max_tokens = orchestrator.SINGLE_THINKING_MAX_TOKENS if payload.thinking else orchestrator.SINGLE_MAX_TOKENS
    results = await orchestrator.execute(
        [expert_key], prompts, payload.message, payload.thinking, max_tokens, None
    )
    r = results[0]

    if not r.get("ok"):
        raise HTTPException(502, f"Expert error: {r.get('error')}")

    elapsed = round(time.time() - start, 2)

    return {
        "expert": expert_key,
        "message": payload.message,
        "thinking": payload.thinking,
        "content": r["content"],
        "reasoning": r.get("reasoning", ""),
        "timings": r.get("timings", {}),
        "elapsed_s": elapsed,
    }
