"""Projects CRUD + API keys management."""
import asyncpg
import os
import json
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from auth import (
    verify_api_key, AuthContext, require_admin,
    require_project_access, generate_api_key, hash_key,
)
from middleware import write_audit

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(prefix="/projects", tags=["projects"])


# ─── Pydantic-схемы ────────────────────────────────────────
class ProjectCreate(BaseModel):
    name: str = Field(..., min_length=2, max_length=200)
    description: Optional[str] = None
    external_id: Optional[str] = None
    metadata: Dict[str, Any] = {}


class ProjectUpdate(BaseModel):
    name: Optional[str] = None
    description: Optional[str] = None
    metadata: Optional[Dict[str, Any]] = None


class KeyCreate(BaseModel):
    name: str = Field(..., min_length=2, max_length=100)
    allowed_experts: Optional[List[str]] = None
    rate_limit_per_min: int = 60
    expires_at: Optional[str] = None  # ISO 8601


# ─── Helpers ───────────────────────────────────────────────
async def _conn():
    return await asyncpg.connect(DATABASE_URL)


def _project_to_dict(row) -> Dict:
    meta = row["metadata"]
    if isinstance(meta, str):
        meta = json.loads(meta)
    return {
        "id": str(row["id"]),
        "external_id": row["external_id"],
        "name": row["name"],
        "description": row["description"],
        "created_by_system": row["created_by_system"],
        "metadata": meta or {},
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "updated_at": row["updated_at"].isoformat() if row["updated_at"] else None,
    }


def _key_to_dict(row) -> Dict:
    allowed = row["allowed_experts"]
    if isinstance(allowed, str):
        allowed = json.loads(allowed)
    return {
        "id": str(row["id"]),
        "name": row["name"],
        "key_prefix": row["key_prefix"],
        "type": row["type"],
        "is_active": row["is_active"],
        "allowed_experts": allowed,
        "rate_limit_per_min": row["rate_limit_per_min"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "last_used_at": row["last_used_at"].isoformat() if row["last_used_at"] else None,
        "expires_at": row["expires_at"].isoformat() if row["expires_at"] else None,
    }


# ─── Проекты ───────────────────────────────────────────────
@router.get("")
async def list_projects(ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        if ctx.is_admin:
            rows = await conn.fetch(
                "SELECT * FROM projects ORDER BY created_at DESC"
            )
        else:
            rows = await conn.fetch(
                "SELECT * FROM projects WHERE id = $1", ctx.project_id
            )
    finally:
        await conn.close()
    return {"projects": [_project_to_dict(r) for r in rows]}


@router.post("", status_code=201)
async def create_project(
    payload: ProjectCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        if payload.external_id:
            exists = await conn.fetchval(
                "SELECT 1 FROM projects WHERE external_id = $1", payload.external_id
            )
            if exists:
                raise HTTPException(409, f"Project with external_id '{payload.external_id}' already exists")

        row = await conn.fetchrow("""
            INSERT INTO projects (name, description, external_id, created_by_system, metadata)
            VALUES ($1, $2, $3, 'admin', $4::jsonb)
            RETURNING *
        """, payload.name, payload.description, payload.external_id,
            json.dumps(payload.metadata))
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="project.create",
        resource_type="project", resource_id=str(row["id"]),
        details={"name": payload.name, "external_id": payload.external_id},
    )
    return _project_to_dict(row)


@router.get("/{project_id}")
async def get_project(project_id: str, ctx: AuthContext = Depends(verify_api_key)):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM projects WHERE id = $1", project_id)
    finally:
        await conn.close()
    if not row:
        raise HTTPException(404, "Project not found")
    return _project_to_dict(row)


@router.patch("/{project_id}")
async def update_project(
    project_id: str,
    payload: ProjectUpdate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM projects WHERE id = $1", project_id)
        if not row:
            raise HTTPException(404, "Project not found")

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
            return _project_to_dict(row)

        values.append(project_id)
        sql = f"UPDATE projects SET {', '.join(fields)} WHERE id = ${idx} RETURNING *"
        row = await conn.fetchrow(sql, *values)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="project.update",
        resource_type="project", resource_id=project_id,
        details={"fields": list(upd.keys())},
    )
    return _project_to_dict(row)


@router.delete("/{project_id}", status_code=204)
async def delete_project(project_id: str, ctx: AuthContext = Depends(verify_api_key)):
    require_admin(ctx)
    conn = await _conn()
    try:
        result = await conn.execute("DELETE FROM projects WHERE id = $1", project_id)
        if result == "DELETE 0":
            raise HTTPException(404, "Project not found")
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="project.delete",
        resource_type="project", resource_id=project_id,
    )


# ─── API-ключи проекта ─────────────────────────────────────
@router.get("/{project_id}/keys")
async def list_keys(project_id: str, ctx: AuthContext = Depends(verify_api_key)):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        rows = await conn.fetch(
            "SELECT * FROM api_keys WHERE project_id = $1 ORDER BY created_at DESC",
            project_id,
        )
    finally:
        await conn.close()
    return {"keys": [_key_to_dict(r) for r in rows]}


@router.post("/{project_id}/keys", status_code=201)
async def create_key(
    project_id: str,
    payload: KeyCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)
    raw_key = generate_api_key()
    key_hash = hash_key(raw_key)
    key_prefix = raw_key[:12]

    conn = await _conn()
    try:
        exists = await conn.fetchval("SELECT 1 FROM projects WHERE id = $1", project_id)
        if not exists:
            raise HTTPException(404, "Project not found")

        row = await conn.fetchrow("""
            INSERT INTO api_keys (project_id, type, name, key_hash, key_prefix,
                                  allowed_experts, rate_limit_per_min, expires_at)
            VALUES ($1, 'project', $2, $3, $4, $5::jsonb, $6, $7::timestamptz)
            RETURNING *
        """, project_id, payload.name, key_hash, key_prefix,
            json.dumps(payload.allowed_experts) if payload.allowed_experts else None,
            payload.rate_limit_per_min,
            payload.expires_at)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="api_key.create",
        resource_type="api_key", resource_id=str(row["id"]),
        project_id=project_id,
        details={"name": payload.name},
    )

    result = _key_to_dict(row)
    result["key"] = raw_key  # plaintext — показываем ОДИН РАЗ
    result["warning"] = "Сохраните ключ — он больше не будет показан"
    return result


@router.delete("/{project_id}/keys/{key_id}", status_code=204)
async def revoke_key(
    project_id: str,
    key_id: str,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)
    conn = await _conn()
    try:
        result = await conn.execute(
            "UPDATE api_keys SET is_active = FALSE WHERE id = $1 AND project_id = $2",
            key_id, project_id,
        )
        if result == "UPDATE 0":
            raise HTTPException(404, "Key not found")
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="api_key.revoke",
        resource_type="api_key", resource_id=key_id,
        project_id=project_id,
    )
