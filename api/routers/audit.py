"""Audit log (admin-only)."""
import asyncpg
import os
import json
from datetime import datetime
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, Query

from auth import verify_api_key, AuthContext, require_admin

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(prefix="/admin/audit", tags=["audit"])


def _to_dict(row) -> Dict:
    details = row["details"]
    if isinstance(details, str):
        details = json.loads(details)
    return {
        "id": row["id"],
        "actor": row["actor"],
        "api_key_id": str(row["api_key_id"]) if row["api_key_id"] else None,
        "project_id": str(row["project_id"]) if row["project_id"] else None,
        "action": row["action"],
        "resource_type": row["resource_type"],
        "resource_id": row["resource_id"],
        "details": details or {},
        "ip": row["ip"],
        "user_agent": row["user_agent"],
        "request_id": row["request_id"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
    }


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


@router.get("")
async def list_audit(
    actor: Optional[str] = Query(None, description="admin или project:<uuid>"),
    action: Optional[str] = Query(None, description="точное совпадение или префикс через %"),
    project_id: Optional[str] = Query(None),
    resource_type: Optional[str] = Query(None),
    from_time: Optional[str] = Query(None, description="ISO timestamp"),
    to_time: Optional[str] = Query(None, description="ISO timestamp"),
    limit: int = Query(50, ge=1, le=500),
    offset: int = Query(0, ge=0),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)

    conditions: List[str] = []
    params: List[Any] = []
    idx = 1

    if actor:
        conditions.append(f"actor = ${idx}")
        params.append(actor)
        idx += 1

    if action:
        if "%" in action or "_" in action:
            conditions.append(f"action LIKE ${idx}")
        else:
            conditions.append(f"action = ${idx}")
        params.append(action)
        idx += 1

    if project_id:
        conditions.append(f"project_id = ${idx}::uuid")
        params.append(project_id)
        idx += 1

    if resource_type:
        conditions.append(f"resource_type = ${idx}")
        params.append(resource_type)
        idx += 1

    if from_time:
        try:
            dt = datetime.fromisoformat(from_time.replace("Z", "+00:00"))
            conditions.append(f"created_at >= ${idx}")
            params.append(dt)
            idx += 1
        except ValueError:
            from fastapi import HTTPException
            raise HTTPException(400, "from_time must be ISO 8601")

    if to_time:
        try:
            dt = datetime.fromisoformat(to_time.replace("Z", "+00:00"))
            conditions.append(f"created_at <= ${idx}")
            params.append(dt)
            idx += 1
        except ValueError:
            from fastapi import HTTPException
            raise HTTPException(400, "to_time must be ISO 8601")

    where = " AND ".join(conditions) if conditions else "TRUE"

    conn = await _conn()
    try:
        rows = await conn.fetch(
            f"SELECT * FROM audit_log WHERE {where} "
            f"ORDER BY created_at DESC LIMIT ${idx} OFFSET ${idx + 1}",
            *params, limit, offset,
        )
        total = await conn.fetchval(
            f"SELECT COUNT(*) FROM audit_log WHERE {where}", *params
        )
    finally:
        await conn.close()

    return {
        "events": [_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.get("/actions")
async def list_actions(ctx: AuthContext = Depends(verify_api_key)):
    require_admin(ctx)
    conn = await _conn()
    try:
        rows = await conn.fetch("""
            SELECT action, COUNT(*) AS count
            FROM audit_log
            GROUP BY action
            ORDER BY count DESC
        """)
    finally:
        await conn.close()

    return {
        "actions": [
            {"action": r["action"], "count": r["count"]}
            for r in rows
        ],
    }


@router.get("/actors")
async def list_actors(ctx: AuthContext = Depends(verify_api_key)):
    require_admin(ctx)
    conn = await _conn()
    try:
        rows = await conn.fetch("""
            SELECT actor, COUNT(*) AS count, MAX(created_at) AS last_seen
            FROM audit_log
            GROUP BY actor
            ORDER BY count DESC
        """)
    finally:
        await conn.close()

    return {
        "actors": [
            {
                "actor": r["actor"],
                "count": r["count"],
                "last_seen": r["last_seen"].isoformat() if r["last_seen"] else None,
            }
            for r in rows
        ],
    }
