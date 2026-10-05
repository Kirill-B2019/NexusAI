"""Experts CRUD."""
import asyncpg
import os
import json
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_admin, filter_experts
import experts_service
from middleware import write_audit

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(prefix="/experts", tags=["experts"])


# ─── Pydantic-схемы ────────────────────────────────────────
class ExpertPublic(BaseModel):
    key: str
    name: str
    description: Optional[str] = None
    icon: Optional[str] = None
    color: Optional[str] = None
    sort_order: int = 100


class ExpertAdmin(ExpertPublic):
    system_prompt: str
    keywords: List[str]
    is_enabled: bool
    is_system: bool
    metadata: Dict[str, Any] = {}


class ExpertCreate(BaseModel):
    key: str = Field(..., min_length=2, max_length=64, pattern=r"^[a-z][a-z0-9_]*$")
    name: str = Field(..., min_length=2, max_length=200)
    description: Optional[str] = None
    system_prompt: str = Field(..., min_length=20)
    keywords: List[str] = []
    icon: Optional[str] = None
    color: Optional[str] = None
    sort_order: int = 100
    is_enabled: bool = True
    metadata: Dict[str, Any] = {}


class ExpertUpdate(BaseModel):
    name: Optional[str] = None
    description: Optional[str] = None
    system_prompt: Optional[str] = None
    keywords: Optional[List[str]] = None
    icon: Optional[str] = None
    color: Optional[str] = None
    sort_order: Optional[int] = None
    is_enabled: Optional[bool] = None
    metadata: Optional[Dict[str, Any]] = None


# ─── Helpers ───────────────────────────────────────────────
async def _conn():
    return await asyncpg.connect(DATABASE_URL)


def _row_to_public(row) -> Dict:
    return {
        "key": row["key"],
        "name": row["name"],
        "description": row["description"],
        "icon": row["icon"],
        "color": row["color"],
        "sort_order": row["sort_order"],
    }


def _row_to_admin(row) -> Dict:
    kw = row["keywords"]
    if isinstance(kw, str):
        kw = json.loads(kw)
    meta = row["metadata"]
    if isinstance(meta, str):
        meta = json.loads(meta)
    return {
        **_row_to_public(row),
        "system_prompt": row["system_prompt"],
        "keywords": kw or [],
        "is_enabled": row["is_enabled"],
        "is_system": row["is_system"],
        "metadata": meta or {},
    }


# ─── Эндпоинты ─────────────────────────────────────────────

@router.get("")
async def list_experts(
    enabled_only: bool = True,
    ctx: AuthContext = Depends(verify_api_key),
):
    """Список экспертов. enabled_only=true (по умолчанию) — только активные."""
    if enabled_only:
        experts = await experts_service.load_all()
    else:
        require_admin(ctx)
        experts = await experts_service.load_all_including_disabled()

    # Фильтрация по allowed_experts ключа
    if ctx.allowed_experts:
        experts = [e for e in experts if e["key"] in ctx.allowed_experts]

    return {"experts": [_row_to_public(e) for e in experts]}


@router.get("/all")
async def list_all_experts(ctx: AuthContext = Depends(verify_api_key)):
    """Все эксперты, включая отключённых (только admin)."""
    require_admin(ctx)
    experts = await experts_service.load_all_including_disabled(force=True)
    return {"experts": [_row_to_admin(e) for e in experts]}


@router.get("/{key}")
async def get_expert(key: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM experts WHERE key = $1 AND deleted_at IS NULL", key
        )
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, f"Expert '{key}' not found")

    if ctx.is_admin:
        return _row_to_admin(row)
    return _row_to_public(row)


@router.post("", status_code=201)
async def create_expert(
    payload: ExpertCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        exists = await conn.fetchval(
            "SELECT 1 FROM experts WHERE key = $1", payload.key
        )
        if exists:
            raise HTTPException(409, f"Expert '{payload.key}' already exists")

        row = await conn.fetchrow("""
            INSERT INTO experts (key, name, description, system_prompt, keywords,
                                 icon, color, sort_order, is_enabled, is_system, metadata)
            VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, $8, $9, FALSE, $10::jsonb)
            RETURNING *
        """, payload.key, payload.name, payload.description, payload.system_prompt,
            json.dumps(payload.keywords), payload.icon, payload.color,
            payload.sort_order, payload.is_enabled, json.dumps(payload.metadata))
    finally:
        await conn.close()

    experts_service.invalidate()
    await write_audit(
        actor=ctx.actor, action="expert.create",
        resource_type="expert", resource_id=payload.key,
        details={"name": payload.name},
    )
    return _row_to_admin(row)


@router.patch("/{key}")
async def update_expert(
    key: str,
    payload: ExpertUpdate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM experts WHERE key = $1 AND deleted_at IS NULL", key
        )
        if not row:
            raise HTTPException(404, f"Expert '{key}' not found")

        # Сохраняем историю промпта
        if payload.system_prompt and payload.system_prompt != row["system_prompt"]:
            await conn.execute("""
                INSERT INTO expert_prompt_history (expert_key, old_prompt, new_prompt, changed_by_actor)
                VALUES ($1, $2, $3, $4)
            """, key, row["system_prompt"], payload.system_prompt, ctx.actor)

        # Собираем SET
        fields = []
        values = []
        idx = 1
        upd = payload.dict(exclude_unset=True)
        for k, v in upd.items():
            if k == "keywords":
                fields.append(f"{k} = ${idx}::jsonb")
                values.append(json.dumps(v))
            elif k == "metadata":
                fields.append(f"{k} = ${idx}::jsonb")
                values.append(json.dumps(v))
            else:
                fields.append(f"{k} = ${idx}")
                values.append(v)
            idx += 1

        if not fields:
            return _row_to_admin(row)

        values.append(key)
        sql = f"UPDATE experts SET {', '.join(fields)} WHERE key = ${idx} RETURNING *"
        row = await conn.fetchrow(sql, *values)
    finally:
        await conn.close()

    experts_service.invalidate()
    await write_audit(
        actor=ctx.actor, action="expert.update",
        resource_type="expert", resource_id=key,
        details={"changed_fields": list(upd.keys())},
    )
    return _row_to_admin(row)


@router.delete("/{key}", status_code=204)
async def delete_expert(key: str, ctx: AuthContext = Depends(verify_api_key)):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT is_system FROM experts WHERE key = $1 AND deleted_at IS NULL", key
        )
        if not row:
            raise HTTPException(404, f"Expert '{key}' not found")
        if row["is_system"]:
            raise HTTPException(403, "Cannot delete system expert. Disable it instead.")

        await conn.execute(
            "UPDATE experts SET deleted_at = NOW() WHERE key = $1", key
        )
    finally:
        await conn.close()

    experts_service.invalidate()
    await write_audit(
        actor=ctx.actor, action="expert.delete",
        resource_type="expert", resource_id=key,
    )


@router.post("/{key}/enable")
async def enable_expert(key: str, ctx: AuthContext = Depends(verify_api_key)):
    return await _toggle(key, True, ctx)


@router.post("/{key}/disable")
async def disable_expert(key: str, ctx: AuthContext = Depends(verify_api_key)):
    return await _toggle(key, False, ctx)


async def _toggle(key: str, enabled: bool, ctx: AuthContext):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow("""
            UPDATE experts SET is_enabled = $1
            WHERE key = $2 AND deleted_at IS NULL
            RETURNING key, is_enabled
        """, enabled, key)
        if not row:
            raise HTTPException(404, f"Expert '{key}' not found")
    finally:
        await conn.close()

    experts_service.invalidate()
    await write_audit(
        actor=ctx.actor,
        action=f"expert.{'enable' if enabled else 'disable'}",
        resource_type="expert", resource_id=key,
    )
    return {"key": row["key"], "is_enabled": row["is_enabled"]}
