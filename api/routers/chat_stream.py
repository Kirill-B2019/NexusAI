"""SSE-стрим чата."""
import asyncio
import httpx
import json
import logging
import os
import time
from typing import Optional, List, AsyncGenerator
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit
import experts_service
import orchestrator
import rag

logger = logging.getLogger("chat_stream")

MODEL_URL = os.getenv("MODEL_SERVER_URL", "http://nexus-model:8080")
MODEL_TIMEOUT = int(os.getenv("MODEL_TIMEOUT", "900"))

router = APIRouter(prefix="/chat", tags=["chat-stream"])


class ChatStreamRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=32000)
    expert: Optional[str] = None
    experts: Optional[List[str]] = None
    thinking: bool = False
    project_id: Optional[str] = None
    use_rag: bool = True
    rag_top_k: int = Field(4, ge=1, le=10)
    rag_min_score: float = Field(0.5, ge=0.0, le=1.0)
    document_ids: Optional[List[str]] = None


def _sse(event: str, data: dict) -> str:
    """Формирует SSE-сообщение."""
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"


async def _stream_expert(
    expert_key: str,
    system_prompt: str,
    message: str,
    thinking: bool,
    max_tokens: int,
    rag_context: Optional[str],
) -> AsyncGenerator[dict, None]:
    """
    Стримит одного эксперта. Yield'ит словари:
    - {"type": "token", "delta": "..."}
    - {"type": "reasoning", "delta": "..."}
    - {"type": "done", "content": "...", "reasoning": "..."}
    """
    full_system = system_prompt
    if rag_context:
        full_system = system_prompt + "\n\n" + rag_context

    payload = {
        "model": "qwen3-4b",
        "messages": [
            {"role": "system", "content": full_system},
            {"role": "user", "content": message}
        ],
        "max_tokens": max_tokens,
        "temperature": 0.6,
        "top_p": 0.9,
        "stream": True,
        "chat_template_kwargs": {"enable_thinking": thinking}
    }

    content_acc = []
    reasoning_acc = []

    try:
        async with httpx.AsyncClient(timeout=MODEL_TIMEOUT) as client:
            async with client.stream(
                "POST",
                f"{MODEL_URL}/v1/chat/completions",
                json=payload,
            ) as response:
                if response.status_code != 200:
                    error_body = await response.aread()
                    yield {
                        "type": "error",
                        "error": f"Model {response.status_code}: {error_body.decode()[:300]}"
                    }
                    return

                async for line in response.aiter_lines():
                    if not line.startswith("data: "):
                        continue
                    data = line[6:].strip()
                    if data == "[DONE]":
                        break
                    try:
                        chunk = json.loads(data)
                    except json.JSONDecodeError:
                        continue

                    choice = chunk.get("choices", [{}])[0]
                    delta = choice.get("delta", {})

                    # reasoning_content (thinking-режим)
                    rc = delta.get("reasoning_content")
                    if rc:
                        reasoning_acc.append(rc)
                        yield {"type": "reasoning", "delta": rc}

                    # обычный content
                    c = delta.get("content")
                    if c:
                        content_acc.append(c)
                        yield {"type": "token", "delta": c}

    except httpx.ReadTimeout:
        yield {"type": "error", "error": "timeout"}
        return
    except Exception as e:
        logger.exception(f"Stream error for {expert_key}")
        yield {"type": "error", "error": str(e)[:300]}
        return

    yield {
        "type": "done",
        "content": "".join(content_acc),
        "reasoning": "".join(reasoning_acc),
    }


@router.post("/stream")
async def chat_stream(
    req: ChatStreamRequest,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    """
    SSE-стрим чата.
    События:
      event: start        — начало, метаданные (mode, experts)
      event: expert_start — начало ответа эксперта
      event: token        — токен финального ответа
      event: reasoning    — токен размышления
      event: expert_done  — завершение эксперта
      event: done         — финал, метаданные
      event: error        — ошибка
    """
    if req.project_id:
        require_project_access(ctx, req.project_id)

    start_ts = time.time()

    # RAG
    rag_context = None
    rag_sources = []
    if req.use_rag and req.project_id:
        try:
            doc_names = {}
            if req.document_ids:
                # Загружаем имена документов через orchestrator (лёгкий запрос)
                import asyncpg
                conn = await asyncpg.connect(os.getenv("DATABASE_URL"))
                try:
                    rows = await conn.fetch(
                        "SELECT id, original_filename FROM documents WHERE id = ANY($1::uuid[])",
                        req.document_ids,
                    )
                    doc_names = {str(r["id"]): r["original_filename"] for r in rows}
                finally:
                    await conn.close()

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
            logger.warning(f"RAG error in stream: {e}")

    # Роутинг
    decision = await orchestrator.route(
        req.message,
        explicit_expert=req.expert,
        explicit_experts=req.experts,
    )

    # Фильтрация по allowed_experts
    used = decision["experts_used"]
    if ctx.allowed_experts and not ctx.is_admin:
        allowed = set(ctx.allowed_experts)
        used = [e for e in used if e in allowed]

    if not used:
        raise HTTPException(403, "Ни один из экспертов не разрешён для этого API-ключа")

    mode = (
        "manual_orchestration" if decision["method"] == "manual"
        else ("single" if len(used) == 1 and decision["method"] == "explicit"
              else "auto_orchestration")
    )

    prompts = await experts_service.get_prompts()
    max_tokens = orchestrator.ORCH_THINKING_MAX_TOKENS if req.thinking else orchestrator.ORCH_MAX_TOKENS

    # Определяем генератор SSE
    async def event_generator() -> AsyncGenerator[str, None]:
        try:
            # start
            yield _sse("start", {
                "mode": mode,
                "experts_used": used,
                "experts_skipped": decision.get("experts_skipped", []),
                "route_method": decision.get("method"),
                "rag_used": bool(rag_sources),
                "sources": rag_sources,
            })

            all_results = []

            for expert_key in used:
                if expert_key not in prompts:
                    yield _sse("expert_error", {"expert": expert_key, "error": "not_found"})
                    continue

                yield _sse("expert_start", {"expert": expert_key})

                content_parts = []
                reasoning_parts = []
                errored = False

                async for chunk in _stream_expert(
                    expert_key, prompts[expert_key], req.message,
                    req.thinking, max_tokens, rag_context,
                ):
                    if chunk["type"] == "token":
                        content_parts.append(chunk["delta"])
                        yield _sse("token", {"expert": expert_key, "delta": chunk["delta"]})
                    elif chunk["type"] == "reasoning":
                        reasoning_parts.append(chunk["delta"])
                        yield _sse("reasoning", {"expert": expert_key, "delta": chunk["delta"]})
                    elif chunk["type"] == "error":
                        errored = True
                        yield _sse("expert_error", {"expert": expert_key, "error": chunk["error"]})
                    elif chunk["type"] == "done":
                        pass

                if not errored:
                    content = "".join(content_parts)
                    all_results.append({
                        "expert": expert_key,
                        "ok": True,
                        "content": content,
                        "reasoning": "".join(reasoning_parts),
                    })
                    yield _sse("expert_done", {
                        "expert": expert_key,
                        "content_length": len(content),
                    })
                else:
                    all_results.append({
                        "expert": expert_key,
                        "ok": False,
                        "error": "stream_failed",
                    })

            # Финальная агрегация — программная (LLM-агрегатор не подходит для стрима)
            ok = [r for r in all_results if r.get("ok")]
            if not ok:
                yield _sse("error", {"error": "all_experts_failed"})
                return

            # Метаданные экспертов для заголовков
            all_experts = await experts_service.load_all()
            experts_meta = {e["key"]: e for e in all_experts}
            final = await orchestrator.aggregate_programmatic(ok, experts_meta)

            elapsed = round(time.time() - start_ts, 2)

            # Аудит
            await write_audit(
                actor=ctx.actor, action=f"chat.stream.{mode}",
                project_id=req.project_id, api_key_id=ctx.api_key_id,
                ip=request.client.host if request.client else None,
                details={
                    "experts_used": used,
                    "elapsed_s": elapsed,
                    "rag_sources": len(rag_sources),
                },
                request_id=getattr(request.state, "request_id", None),
            )

            yield _sse("done", {
                "mode": mode,
                "experts_used": used,
                "aggregated": final,
                "sources": rag_sources,
                "elapsed_s": elapsed,
                "summary": {
                    "total": len(all_results),
                    "ok": len(ok),
                    "failed": len(all_results) - len(ok),
                },
            })

        except asyncio.CancelledError:
            logger.info("Stream cancelled by client")
            raise
        except Exception as e:
            logger.exception("Stream unexpected error")
            yield _sse("error", {"error": str(e)[:300]})

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
        },
    )
