"""Decisions CRUD."""
import asyncpg
import os
import json
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(tags=["decisions"])

VALID_STATUSES = {"active", "superseded", "archived"}


class DecisionCreate(BaseModel):
    title: str = Field(..., min_length=2, max_length=300)
    content: str = Field(..., min_length=1)
    source_message_id: Optional[str] = None
    status: str = "active"
    external_id: Optional[str] = None
    metadata: Dict[str, Any] = {}


class DecisionUpdate(BaseModel):
    title: Optional[str] = Field(None, min_length=2, max_length=300)
    content: Optional[str] = None
    status: Optional[str] = None
    metadata: Optional[Dict[str, Any]] = None


def _to_dict(row) -> Dict:
    meta = row["metadata"]
    if isinstance(meta, str):
        meta = json.loads(meta)
    return {
        "id": str(row["id"]),
        "project_id": str(row["project_id"]),
        "external_id": row["external_id"],
        "title": row["title"],
        "content": row["content"],
        "source_message_id": str(row["source_message_id"]) if row["source_message_id"] else None,
        "status": row["status"],
        "metadata": meta or {},
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "updated_at": row["updated_at"].isoformat() if row["updated_at"] else None,
    }


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


@router.get("/projects/{project_id}/decisions")
async def list_decisions(
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
                SELECT * FROM decisions
                WHERE project_id = $1 AND status = $2
                ORDER BY created_at DESC
                LIMIT $3 OFFSET $4
            """, project_id, status, limit, offset)
            total = await conn.fetchval(
                "SELECT COUNT(*) FROM decisions WHERE project_id = $1 AND status = $2",
                project_id, status,
            )
        else:
            rows = await conn.fetch("""
                SELECT * FROM decisions
                WHERE project_id = $1
                ORDER BY created_at DESC
                LIMIT $2 OFFSET $3
            """, project_id, limit, offset)
            total = await conn.fetchval(
                "SELECT COUNT(*) FROM decisions WHERE project_id = $1", project_id
            )
    finally:
        await conn.close()

    return {
        "decisions": [_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.post("/projects/{project_id}/decisions", status_code=201)
async def create_decision(
    project_id: str,
    payload: DecisionCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)

    if payload.status not in VALID_STATUSES:
        raise HTTPException(400, f"Invalid status. Allowed: {VALID_STATUSES}")

    conn = await _conn()
    try:
        exists = await conn.fetchval("SELECT 1 FROM projects WHERE id = $1", project_id)
        if not exists:
            raise HTTPException(404, "Project not found")

        if payload.source_message_id:
            msg = await conn.fetchval(
                "SELECT 1 FROM messages WHERE id = $1", payload.source_message_id
            )
            if not msg:
                raise HTTPException(400, "source_message_id not found")

        row = await conn.fetchrow("""
            INSERT INTO decisions
                (project_id, title, content, source_message_id, status,
                 external_id, metadata)
            VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb)
            RETURNING *
        """, project_id, payload.title, payload.content,
            payload.source_message_id, payload.status,
            payload.external_id, json.dumps(payload.metadata))
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="decision.create",
        resource_type="decision", resource_id=str(row["id"]),
        project_id=project_id,
        details={"title": payload.title, "status": payload.status},
    )
    return _to_dict(row)


@router.get("/decisions/{decision_id}")
async def get_decision(
    decision_id: str,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM decisions WHERE id = $1", decision_id)
        if not row:
            raise HTTPException(404, "Decision not found")
        require_project_access(ctx, str(row["project_id"]))
    finally:
        await conn.close()

    return _to_dict(row)


@router.patch("/decisions/{decision_id}")
async def update_decision(
    decision_id: str,
    payload: DecisionUpdate,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM decisions WHERE id = $1", decision_id)
        if not row:
            raise HTTPException(404, "Decision not found")
        require_project_access(ctx, str(row["project_id"]))

        upd = payload.dict(exclude_unset=True)
        if "status" in upd and upd["status"] not in VALID_STATUSES:
            raise HTTPException(400, f"Invalid status. Allowed: {VALID_STATUSES}")

        fields = []
        values = []
        idx = 1
        for k, v in upd.items():
            if k == "metadata":
                fields.append(f"{k} = ${idx}::jsonb")
                values.append(json.dumps(v))
            else:
                fields.append(f"{k} = ${idx}")
                values.append(v)
            idx += 1

        if not fields:
            return _to_dict(row)

        values.append(decision_id)
        sql = f"UPDATE decisions SET {', '.join(fields)} WHERE id = ${idx} RETURNING *"
        row = await conn.fetchrow(sql, *values)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="decision.update",
        resource_type="decision", resource_id=decision_id,
        details={"fields": list(upd.keys())},
    )
    return _to_dict(row)


@router.delete("/decisions/{decision_id}", status_code=204)
async def delete_decision(
    decision_id: str,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT project_id FROM decisions WHERE id = $1", decision_id
        )
        if not row:
            raise HTTPException(404, "Decision not found")
        require_project_access(ctx, str(row["project_id"]))
        await conn.execute("DELETE FROM decisions WHERE id = $1", decision_id)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="decision.delete",
        resource_type="decision", resource_id=decision_id,
    )
