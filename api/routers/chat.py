"""Chat routes: single / auto / manual + RAG with document filter."""
import asyncpg
import os
import json
import time
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit
import experts_service
import orchestrator
import rag

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(prefix="/chat", tags=["chat"])


class ChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=32000)
    expert: Optional[str] = None
    experts: Optional[List[str]] = None
    thinking: bool = False
    project_id: Optional[str] = None
    conversation_id: Optional[str] = None
    orchestrate: bool = True
    use_llm_aggregator: bool = False
    save_to_conversation: bool = False
    use_rag: bool = True
    rag_top_k: int = Field(4, ge=1, le=10)
    rag_min_score: float = Field(0.5, ge=0.0, le=1.0)
    document_ids: Optional[List[str]] = None   # ← НОВОЕ: фильтр по документам


class RouteRequest(BaseModel):
    message: str
    expert: Optional[str] = None
    experts: Optional[List[str]] = None


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


async def _load_document_names(document_ids: List[str]) -> Dict[str, str]:
    """Загружает {document_id: original_filename} для указанных ID."""
    if not document_ids:
        return {}
    conn = await _conn()
    try:
        rows = await conn.fetch("""
            SELECT id, original_filename FROM documents
            WHERE id = ANY($1::uuid[])
        """, document_ids)
        return {str(r["id"]): r["original_filename"] for r in rows}
    finally:
        await conn.close()


async def _save_message(
    conversation_id: str,
    role: str,
    content: str,
    expert: Optional[str] = None,
    experts_used: Optional[List[str]] = None,
    mode: Optional[str] = None,
    reasoning: Optional[str] = None,
    metadata: Optional[Dict] = None,
) -> str:
    conn = await _conn()
    try:
        exists = await conn.fetchval(
            "SELECT 1 FROM conversations WHERE id = $1", conversation_id
        )
        if not exists:
            raise HTTPException(404, f"Conversation '{conversation_id}' not found")

        msg_id = await conn.fetchval("""
            INSERT INTO messages
                (conversation_id, role, expert, experts_used, content, reasoning, mode, metadata)
            VALUES ($1, $2, $3, $4::jsonb, $5, $6, $7, $8::jsonb)
            RETURNING id
        """, conversation_id, role, expert,
            json.dumps(experts_used) if experts_used else None,
            content, reasoning, mode,
            json.dumps(metadata) if metadata else None)

        # Автозаголовок: если роль user и заголовок пустой — ставим первые 60 символов
        if role == "user":
            conv = await conn.fetchrow(
                "SELECT title FROM conversations WHERE id = $1", conversation_id
            )
            if conv and not conv["title"]:
                auto_title = content.strip().replace("\n", " ")[:60]
                await conn.execute(
                    "UPDATE conversations SET title = $1 WHERE id = $2",
                    auto_title, conversation_id,
                )

        await conn.execute(
            "UPDATE conversations SET updated_at = NOW() WHERE id = $1",
            conversation_id,
        )
        return str(msg_id)
    finally:
        await conn.close()


def _filter_by_allowed(ctx: AuthContext, experts: List[str]) -> List[str]:
    if ctx.is_admin or not ctx.allowed_experts:
        return experts
    allowed = set(ctx.allowed_experts)
    return [e for e in experts if e in allowed]


@router.post("/route")
async def route_preview(
    req: RouteRequest,
    ctx: AuthContext = Depends(verify_api_key),
):
    decision = await orchestrator.route(
        req.message,
        explicit_expert=req.expert,
        explicit_experts=req.experts,
    )
    decision["experts_used"] = _filter_by_allowed(ctx, decision["experts_used"])
    return decision


@router.post("")
async def chat(
    req: ChatRequest,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    if req.project_id:
        require_project_access(ctx, req.project_id)

    start = time.time()

    # ─── RAG ───────────────────────────────────────────────
    rag_context = None
    rag_sources = []
    if req.use_rag and req.project_id:
        try:
            # Загружаем имена документов (если указан фильтр)
            doc_names = {}
            if req.document_ids:
                doc_names = await _load_document_names(req.document_ids)

            rag_results = await rag.search_context(
                project_id=req.project_id,
                query=req.message,
                top_k=req.rag_top_k,
                min_score=req.rag_min_score,
                document_ids=req.document_ids,
            )
            if rag_results:
                rag_context = await rag.build_context(
                    req.project_id, req.message,
                    top_k=req.rag_top_k,
                    min_score=req.rag_min_score,
                    document_ids=req.document_ids,
                    document_names=doc_names,
                )
                rag_sources = rag.extract_sources(rag_results, doc_names)
        except Exception as e:
            print(f"RAG error: {e}")

    # ─── Single ────────────────────────────────────────────
    if req.expert and not req.orchestrate and not req.experts:
        if ctx.allowed_experts and req.expert not in ctx.allowed_experts:
            raise HTTPException(403, f"Expert '{req.expert}' not allowed for this key")

        prompts = await experts_service.get_prompts()
        if req.expert not in prompts:
            raise HTTPException(404, f"Expert '{req.expert}' not found or disabled")

        max_tokens = orchestrator.SINGLE_THINKING_MAX_TOKENS if req.thinking else orchestrator.SINGLE_MAX_TOKENS
        results = await orchestrator.execute(
            [req.expert], prompts, req.message, req.thinking, max_tokens, rag_context
        )
        r = results[0]
        if not r.get("ok"):
            raise HTTPException(502, f"Expert error: {r.get('error')}")

        elapsed = round(time.time() - start, 2)

        message_ids = None
        if req.save_to_conversation and req.conversation_id:
            user_msg_id = await _save_message(
                req.conversation_id, "user", req.message,
                metadata={"expert": req.expert, "thinking": req.thinking,
                          "rag_used": bool(rag_sources),
                          "document_ids": req.document_ids},
            )
            asst_msg_id = await _save_message(
                req.conversation_id, "assistant", r["content"],
                expert=req.expert, mode="single",
                reasoning=r.get("reasoning", ""),
                metadata={"sources": rag_sources} if rag_sources else None,
            )
            message_ids = {"user": user_msg_id, "assistant": asst_msg_id}

        await write_audit(
            actor=ctx.actor, action="chat.single",
            resource_type="expert", resource_id=req.expert,
            project_id=req.project_id, api_key_id=ctx.api_key_id,
            ip=request.client.host if request.client else None,
            details={"elapsed_s": elapsed, "thinking": req.thinking,
                     "rag_sources": len(rag_sources),
                     "document_filter": req.document_ids},
            request_id=getattr(request.state, "request_id", None),
        )

        return {
            "mode": "single",
            "expert": req.expert,
            "content": r["content"],
            "reasoning": r.get("reasoning", ""),
            "timings": r.get("timings", {}),
            "elapsed_s": elapsed,
            "rag_used": bool(rag_sources),
            "sources": rag_sources,
            "message_ids": message_ids,
        }

    # ─── Orchestration ─────────────────────────────────────
    decision = await orchestrator.route(
        req.message,
        explicit_expert=req.expert,
        explicit_experts=req.experts,
    )
    used = _filter_by_allowed(ctx, decision["experts_used"])

    if not used:
        raise HTTPException(403, "Ни один из экспертов не разрешён для этого API-ключа")

    mode = "manual_orchestration" if decision["method"] == "manual" else "auto_orchestration"

    prompts = await experts_service.get_prompts()
    max_tokens = orchestrator.ORCH_THINKING_MAX_TOKENS if req.thinking else orchestrator.ORCH_MAX_TOKENS

    results = await orchestrator.execute(
        used, prompts, req.message, req.thinking, max_tokens, rag_context
    )
    ok_count = sum(1 for r in results if r.get("ok"))

    if ok_count == 0:
        errors = [f"{r['expert']}: {r.get('error')}" for r in results]
        raise HTTPException(502, "All experts failed: " + "; ".join(errors))

    all_experts = await experts_service.load_all()
    experts_meta = {e["key"]: e for e in all_experts}

    if req.use_llm_aggregator and ok_count > 1:
        final = await orchestrator.aggregate_llm(results, req.message, experts_meta)
        agg_mode = "llm"
    else:
        final = await orchestrator.aggregate_programmatic(results, experts_meta)
        agg_mode = "programmatic"

    elapsed = round(time.time() - start, 2)

    message_ids = None
    if req.save_to_conversation and req.conversation_id:
        user_msg_id = await _save_message(
            req.conversation_id, "user", req.message,
            metadata={"thinking": req.thinking, "mode": mode,
                      "rag_used": bool(rag_sources),
                      "document_ids": req.document_ids},
        )
        asst_msg_id = await _save_message(
            req.conversation_id, "assistant", final,
            experts_used=used, mode=mode,
            metadata={"aggregator": agg_mode,
                      "sources": rag_sources} if rag_sources else {"aggregator": agg_mode},
        )
        message_ids = {"user": user_msg_id, "assistant": asst_msg_id}

    await write_audit(
        actor=ctx.actor, action=f"chat.{mode}",
        project_id=req.project_id, api_key_id=ctx.api_key_id,
        ip=request.client.host if request.client else None,
        details={
            "experts_used": used,
            "experts_skipped": decision.get("experts_skipped", []),
            "elapsed_s": elapsed,
            "rag_sources": len(rag_sources),
            "document_filter": req.document_ids,
        },
        request_id=getattr(request.state, "request_id", None),
    )

    return {
        "mode": mode,
        "experts_requested": decision.get("experts_requested"),
        "experts_used": used,
        "experts_skipped": decision.get("experts_skipped", []),
        "route_reason": decision.get("reason"),
        "route_method": decision.get("method"),
        "results": results,
        "aggregated": final,
        "aggregator": agg_mode,
        "summary": {
            "total": len(used),
            "ok": ok_count,
            "failed": len(used) - ok_count,
        },
        "elapsed_s": elapsed,
        "rag_used": bool(rag_sources),
        "sources": rag_sources,
        "message_ids": message_ids,
    }
