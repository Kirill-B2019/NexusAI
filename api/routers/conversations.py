"""Conversations and messages."""
import asyncpg
import os
import json
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(tags=["conversations"])


# ─── Схемы ─────────────────────────────────────────────────
class ConversationCreate(BaseModel):
    title: Optional[str] = Field(None, max_length=200)
    external_id: Optional[str] = None
    metadata: Dict[str, Any] = {}


class ConversationUpdate(BaseModel):
    title: Optional[str] = Field(None, max_length=200)
    metadata: Optional[Dict[str, Any]] = None


def _conv_to_dict(row) -> Dict:
    meta = row["metadata"]
    if isinstance(meta, str):
        meta = json.loads(meta)
    return {
        "id": str(row["id"]),
        "project_id": str(row["project_id"]),
        "external_id": row["external_id"],
        "title": row["title"],
        "metadata": meta or {},
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "updated_at": row["updated_at"].isoformat() if row["updated_at"] else None,
    }


def _msg_to_dict(row) -> Dict:
    meta = row["metadata"]
    if isinstance(meta, str):
        meta = json.loads(meta)
    eu = row["experts_used"]
    if isinstance(eu, str):
        eu = json.loads(eu)
    return {
        "id": str(row["id"]),
        "conversation_id": str(row["conversation_id"]),
        "role": row["role"],
        "expert": row["expert"],
        "experts_used": eu,
        "content": row["content"],
        "reasoning": row["reasoning"],
        "mode": row["mode"],
        "metadata": meta or {},
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
    }


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


# ─── Диалоги ───────────────────────────────────────────────
@router.get("/projects/{project_id}/conversations")
async def list_conversations(
    project_id: str,
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        rows = await conn.fetch("""
            SELECT * FROM conversations
            WHERE project_id = $1
            ORDER BY updated_at DESC
            LIMIT $2 OFFSET $3
        """, project_id, limit, offset)
        total = await conn.fetchval(
            "SELECT COUNT(*) FROM conversations WHERE project_id = $1",
            project_id,
        )
    finally:
        await conn.close()

    return {
        "conversations": [_conv_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.post("/projects/{project_id}/conversations", status_code=201)
async def create_conversation(
    project_id: str,
    payload: ConversationCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        exists = await conn.fetchval(
            "SELECT 1 FROM projects WHERE id = $1", project_id
        )
        if not exists:
            raise HTTPException(404, "Project not found")

        row = await conn.fetchrow("""
            INSERT INTO conversations (project_id, title, external_id, metadata)
            VALUES ($1, $2, $3, $4::jsonb)
            RETURNING *
        """, project_id, payload.title, payload.external_id,
            json.dumps(payload.metadata))
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="conversation.create",
        resource_type="conversation", resource_id=str(row["id"]),
        project_id=project_id,
    )
    return _conv_to_dict(row)


@router.get("/conversations/{conv_id}")
async def get_conversation(
    conv_id: str,
    include_messages: bool = Query(False),
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM conversations WHERE id = $1", conv_id
        )
        if not row:
            raise HTTPException(404, "Conversation not found")
        require_project_access(ctx, str(row["project_id"]))

        result = _conv_to_dict(row)

        if include_messages:
            msgs = await conn.fetch("""
                SELECT * FROM messages
                WHERE conversation_id = $1
                ORDER BY created_at ASC
            """, conv_id)
            result["messages"] = [_msg_to_dict(m) for m in msgs]
    finally:
        await conn.close()

    return result


@router.patch("/conversations/{conv_id}")
async def update_conversation(
    conv_id: str,
    payload: ConversationUpdate,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM conversations WHERE id = $1", conv_id
        )
        if not row:
            raise HTTPException(404, "Conversation not found")
        require_project_access(ctx, str(row["project_id"]))

        fields = []
        values = []
        idx = 1
        upd = payload.dict(exclude_unset=True)
        for k, v in upd.items():
            if k == "metadata":
                fields.append(f"{k} = ${idx}::jsonb")
                values.append(json.dumps(v))
            else:
                fields.append(f"{k} = ${idx}")
                values.append(v)
            idx += 1

        if not fields:
            return _conv_to_dict(row)

        values.append(conv_id)
        sql = f"UPDATE conversations SET {', '.join(fields)} WHERE id = ${idx} RETURNING *"
        row = await conn.fetchrow(sql, *values)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="conversation.update",
        resource_type="conversation", resource_id=conv_id,
        details={"fields": list(upd.keys())},
    )
    return _conv_to_dict(row)


@router.delete("/conversations/{conv_id}", status_code=204)
async def delete_conversation(
    conv_id: str,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT project_id FROM conversations WHERE id = $1", conv_id
        )
        if not row:
            raise HTTPException(404, "Conversation not found")
        require_project_access(ctx, str(row["project_id"]))
        await conn.execute("DELETE FROM conversations WHERE id = $1", conv_id)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="conversation.delete",
        resource_type="conversation", resource_id=conv_id,
    )


# ─── Сообщения ─────────────────────────────────────────────
@router.get("/conversations/{conv_id}/messages")
async def list_messages(
    conv_id: str,
    before_id: Optional[str] = Query(None, description="Cursor: только до этого message_id"),
    limit: int = Query(50, ge=1, le=200),
    ctx: AuthContext = Depends(verify_api_key),
):
    """Cursor-based пагинация: возвращает последние limit сообщений перед before_id."""
    conn = await _conn()
    try:
        conv = await conn.fetchrow(
            "SELECT project_id FROM conversations WHERE id = $1", conv_id
        )
        if not conv:
            raise HTTPException(404, "Conversation not found")
        require_project_access(ctx, str(conv["project_id"]))

        if before_id:
            rows = await conn.fetch("""
                SELECT * FROM messages
                WHERE conversation_id = $1
                  AND created_at < (SELECT created_at FROM messages WHERE id = $2)
                ORDER BY created_at DESC
                LIMIT $3
            """, conv_id, before_id, limit)
        else:
            rows = await conn.fetch("""
                SELECT * FROM messages
                WHERE conversation_id = $1
                ORDER BY created_at DESC
                LIMIT $2
            """, conv_id, limit)

        # Возвращаем в хронологическом порядке
        rows = list(reversed(rows))

        next_before_id = None
        if len(rows) == limit:
            next_before_id = str(rows[0]["id"])
    finally:
        await conn.close()

    return {
        "messages": [_msg_to_dict(r) for r in rows],
        "next_before_id": next_before_id,
        "count": len(rows),
    }


@router.get("/messages/{msg_id}")
async def get_message(msg_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM messages WHERE id = $1", msg_id)
        if not row:
            raise HTTPException(404, "Message not found")

        conv = await conn.fetchrow(
            "SELECT project_id FROM conversations WHERE id = $1",
            row["conversation_id"],
        )
        require_project_access(ctx, str(conv["project_id"]))
    finally:
        await conn.close()

    return _msg_to_dict(row)


@router.delete("/messages/{msg_id}", status_code=204)
async def delete_message(msg_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM messages WHERE id = $1", msg_id)
        if not row:
            raise HTTPException(404, "Message not found")

        conv = await conn.fetchrow(
            "SELECT project_id FROM conversations WHERE id = $1",
            row["conversation_id"],
        )
        require_project_access(ctx, str(conv["project_id"]))
        await conn.execute("DELETE FROM messages WHERE id = $1", msg_id)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="message.delete",
        resource_type="message", resource_id=msg_id,
    )
