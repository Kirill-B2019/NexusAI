"""Реестр LoRA-адаптеров экспертов: загрузка версий, публикация, откат.

Артефакт (файл .gguf) хранится на диске контейнера API в LORA_STORAGE_DIR
(контент-адресуемый путь {expert}/{sha256}.gguf — одинаковые файлы не
дублируются). В БД — метаданные, хеш, происхождение (dataset release +
training manifest) и жизненный цикл: candidate → published → rolled_back /
archived. Ровно один published-адаптер на эксперта гарантируется частичным
уникальным индексом. Публикация в БД не перезапускает inference-сервер:
применение адаптера на model-server — отдельный шаг оператора (см.
docs/OPERATIONS.md).
"""
import hashlib
import json
import os
from pathlib import Path
from typing import Any
from uuid import UUID

import asyncpg
from fastapi import APIRouter, Depends, File, Form, HTTPException, Request, UploadFile
from pydantic import BaseModel

import lora_runtime

from auth import AuthContext, require_admin, verify_api_key
from middleware import request_ip, request_user_agent, write_audit

DATABASE_URL = os.getenv("DATABASE_URL")
LORA_STORAGE_DIR = os.getenv("LORA_STORAGE_DIR", "/app/data/loras")
MAX_ADAPTER_BYTES = 256 * 1024 * 1024

router = APIRouter(prefix="/admin/training/adapters", tags=["training-adapters"])


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


def _json_value(value: Any) -> Any:
    return json.loads(value) if isinstance(value, str) else value


def _adapter_summary(row) -> dict[str, Any]:
    return {
        "id": str(row["id"]),
        "expert": row["expert_key"],
        "version": row["version"],
        "status": row["status"],
        "filename": Path(row["storage_path"]).name,
        "sha256": row["file_sha256"],
        "size_bytes": row["file_size_bytes"],
        "base_model": row["base_model"],
        "dataset_release_id": (
            str(row["dataset_release_id"]) if row["dataset_release_id"] else None
        ),
        "created_by": row["created_by"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "published_by": row["published_by"],
        "published_at": row["published_at"].isoformat() if row["published_at"] else None,
        "rolled_back_by": row["rolled_back_by"],
        "rolled_back_at": (
            row["rolled_back_at"].isoformat() if row["rolled_back_at"] else None
        ),
    }


async def _ensure_expert_exists(conn, expert: str) -> None:
    expert_exists = await conn.fetchval(
        "SELECT 1 FROM experts WHERE key = $1 AND deleted_at IS NULL",
        expert,
    )
    if not expert_exists:
        raise HTTPException(404, "Эксперт не найден.")


async def _ensure_release_belongs_to_expert(conn, release_id: UUID, expert: str) -> None:
    release_expert = await conn.fetchval(
        "SELECT expert_key FROM training_dataset_releases WHERE id = $1",
        release_id,
    )
    if release_expert is None:
        raise HTTPException(404, "Снимок датасета не найден.")
    if release_expert != expert:
        raise HTTPException(
            422,
            "Снимок датасета относится к другому эксперту.",
        )


def _parse_json_object(raw: str | None, field: str) -> dict[str, Any]:
    if raw is None or not raw.strip():
        return {}
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exception:
        raise HTTPException(
            422,
            f"Поле {field} должно быть корректным JSON-объектом.",
        ) from exception
    if not isinstance(parsed, dict):
        raise HTTPException(422, f"Поле {field} должно быть JSON-объектом.")
    return parsed


def _save_adapter_file(expert: str, sha256: str, content: bytes) -> str:
    """Пишет файл артефакта атомарно, возвращает относительный путь."""
    storage_root = Path(LORA_STORAGE_DIR)
    expert_dir = storage_root / expert
    expert_dir.mkdir(parents=True, exist_ok=True)
    relative_path = f"{expert}/{sha256}.gguf"
    target = storage_root / relative_path
    if not target.exists():
        temp = target.with_suffix(".gguf.tmp")
        temp.write_bytes(content)
        os.replace(temp, target)
    return relative_path


def _delete_adapter_file_if_orphan(relative_path: str, remaining_refs: int) -> None:
    if remaining_refs > 0:
        return
    target = Path(LORA_STORAGE_DIR) / relative_path
    try:
        target.unlink(missing_ok=True)
    except OSError:
        # Файл может быть заблокирован или недоступен — реестр уже очищен.
        pass


class LoraAdapterPublish(BaseModel):
    pass


@router.post("/", status_code=201)
async def upload_lora_adapter(
    request: Request,
    expert: str = Form(..., min_length=1, max_length=100),
    base_model: str = Form(..., min_length=1, max_length=100),
    file: UploadFile = File(...),
    dataset_release_id: str | None = Form(None),
    training_manifest: str | None = Form(None),
    metrics: str | None = Form(None),
    notes: str | None = Form(None, max_length=4000),
    ctx: AuthContext = Depends(verify_api_key),
):
    """Загружает новую версию LoRA-адаптера эксперта (статус candidate)."""
    require_admin(ctx)

    if not file.filename or not file.filename.lower().endswith(".gguf"):
        raise HTTPException(422, "Ожидается файл адаптера с расширением .gguf.")

    content = await file.read(MAX_ADAPTER_BYTES + 1)
    if len(content) > MAX_ADAPTER_BYTES:
        raise HTTPException(
            413,
            f"Файл превышает ограничение {MAX_ADAPTER_BYTES // (1024 * 1024)} МБ.",
        )
    if not content:
        raise HTTPException(422, "Файл адаптера пуст.")

    release_uuid: UUID | None = None
    if dataset_release_id:
        try:
            release_uuid = UUID(dataset_release_id)
        except ValueError as exception:
            raise HTTPException(
                422, "dataset_release_id должен быть UUID."
            ) from exception

    manifest = _parse_json_object(training_manifest, "training_manifest")
    adapter_metrics = _parse_json_object(metrics, "metrics")

    sha256 = hashlib.sha256(content).hexdigest()
    reused = False

    conn = await _conn()
    try:
        async with conn.transaction():
            await _ensure_expert_exists(conn, expert)
            if release_uuid is not None:
                await _ensure_release_belongs_to_expert(conn, release_uuid, expert)

            existing = await conn.fetchrow(
                """
                SELECT * FROM lora_adapters
                WHERE expert_key = $1 AND file_sha256 = $2
                ORDER BY created_at DESC
                LIMIT 1
                """,
                expert,
                sha256,
            )
            if existing:
                row = existing
                reused = True
            else:
                storage_path = _save_adapter_file(expert, sha256, content)
                try:
                    row = await conn.fetchrow(
                        """
                        INSERT INTO lora_adapters
                            (expert_key, version, status, storage_path, file_sha256,
                             file_size_bytes, base_model, dataset_release_id,
                             training_manifest, metrics, notes, created_by)
                        SELECT $1, COALESCE(MAX(version), 0) + 1, 'candidate', $2, $3,
                               $4, $5, $6::uuid, $7::jsonb, $8::jsonb, $9, $10
                        FROM lora_adapters
                        WHERE expert_key = $1
                        RETURNING *
                        """,
                        expert,
                        storage_path,
                        sha256,
                        len(content),
                        base_model[:100],
                        release_uuid,
                        json.dumps(manifest, ensure_ascii=False),
                        json.dumps(adapter_metrics, ensure_ascii=False),
                        notes,
                        ctx.actor,
                    )
                except asyncpg.UniqueViolationError:
                    # Параллельная загрузка той же версии — повторяем выборку.
                    row = await conn.fetchrow(
                        """
                        SELECT * FROM lora_adapters
                        WHERE expert_key = $1 AND file_sha256 = $2
                        ORDER BY created_at DESC
                        LIMIT 1
                        """,
                        expert,
                        sha256,
                    )
                    if not row:
                        raise
                    reused = True
    finally:
        await conn.close()

    audit_meta = {
        "ip": request_ip(request),
        "user_agent": request_user_agent(request),
        "request_id": getattr(request.state, "request_id", None),
    }

    if reused:
        await write_audit(
            actor=ctx.actor,
            action="lora_adapter.reuse",
            resource_type="lora_adapter",
            resource_id=str(row["id"]),
            details={
                "expert": expert,
                "sha256": sha256,
                "filename": file.filename[:255],
            },
            **audit_meta,
        )
    else:
        await write_audit(
            actor=ctx.actor,
            action="lora_adapter.upload",
            resource_type="lora_adapter",
            resource_id=str(row["id"]),
            details={
                "expert": expert,
                "version": row["version"],
                "sha256": sha256,
                "size_bytes": len(content),
                "base_model": base_model[:100],
                "dataset_release_id": str(release_uuid) if release_uuid else None,
            },
            **audit_meta,
        )

    return {**_adapter_summary(row), "reused": reused}


@router.get("/")
async def list_lora_adapters(
    expert: str | None = None,
    status: str | None = None,
    limit: int = 50,
    offset: int = 0,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    if status is not None and status not in (
        "candidate",
        "published",
        "archived",
        "rolled_back",
    ):
        raise HTTPException(
            422,
            "Допустимые статусы: candidate, published, archived, rolled_back.",
        )
    if not 1 <= limit <= 100 or offset < 0:
        raise HTTPException(422, "limit должен быть 1–100, offset — неотрицательным.")

    conn = await _conn()
    try:
        rows = await conn.fetch(
            """
            SELECT * FROM lora_adapters
            WHERE ($1::text IS NULL OR expert_key = $1)
              AND ($2::text IS NULL OR status = $2)
            ORDER BY expert_key, version DESC
            LIMIT $3 OFFSET $4
            """,
            expert, status, limit, offset,
        )
        total = await conn.fetchval(
            """
            SELECT COUNT(*) FROM lora_adapters
            WHERE ($1::text IS NULL OR expert_key = $1)
              AND ($2::text IS NULL OR status = $2)
            """,
            expert, status,
        )
    finally:
        await conn.close()

    return {
        "adapters": [_adapter_summary(row) for row in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.get("/{adapter_id}")
async def get_lora_adapter(
    adapter_id: UUID,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM lora_adapters WHERE id = $1",
            adapter_id,
        )
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Адаптер не найден.")

    return {
        **_adapter_summary(row),
        "storage_path": row["storage_path"],
        "training_manifest": _json_value(row["training_manifest"]),
        "metrics": _json_value(row["metrics"]),
        "notes": row["notes"],
    }


@router.post("/{adapter_id}/publish")
async def publish_lora_adapter(
    adapter_id: UUID,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    """Делает адаптер активным: предыдущий published переводится в rolled_back.

    Применение на inference-сервере (перезапуск model с --lora) остаётся шагом
    оператора (`scripts/apply-loras.sh`), но при `LORA_APPLY_ON_PUBLISH=true` и
    заданном `MODEL_RELOAD_URL` API дополнительно уведомляет внешний
    перезагрузчик модели.
    """
    require_admin(ctx)
    demoted_version: int | None = None
    conn = await _conn()
    try:
        async with conn.transaction():
            row = await conn.fetchrow(
                "SELECT * FROM lora_adapters WHERE id = $1 FOR UPDATE",
                adapter_id,
            )
            if not row:
                raise HTTPException(404, "Адаптер не найден.")
            if row["status"] == "published":
                raise HTTPException(409, "Адаптер уже опубликован.")
            if row["status"] == "archived":
                raise HTTPException(
                    409,
                    "Архивный адаптер нельзя опубликовать — загрузите новую версию.",
                )

            previous = await conn.fetchrow(
                """
                SELECT * FROM lora_adapters
                WHERE expert_key = $1 AND status = 'published'
                FOR UPDATE
                """,
                row["expert_key"],
            )
            if previous and str(previous["id"]) != str(adapter_id):
                await conn.execute(
                    """
                    UPDATE lora_adapters
                    SET status = 'rolled_back', rolled_back_by = $1, rolled_back_at = NOW()
                    WHERE id = $2
                    """,
                    ctx.actor,
                    previous["id"],
                )
                demoted_version = previous["version"]

            row = await conn.fetchrow(
                """
                UPDATE lora_adapters
                SET status = 'published', published_by = $1, published_at = NOW()
                WHERE id = $2
                RETURNING *
                """,
                ctx.actor,
                adapter_id,
            )
    finally:
        await conn.close()

    lora_runtime.reset_cache()
    await write_audit(
        actor=ctx.actor,
        action="lora_adapter.publish",
        resource_type="lora_adapter",
        resource_id=str(adapter_id),
        details={
            "expert": row["expert_key"],
            "version": row["version"],
            "demoted_version": demoted_version,
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None),
    )
    reload = await lora_runtime.request_model_reload(
        reason=f"publish:{row['expert_key']}",
    )
    return {**_adapter_summary(row), "model_reload": reload}


@router.post("/{adapter_id}/rollback")
async def rollback_lora_adapter(
    adapter_id: UUID,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    """Снимает адаптер с публикации (published → rolled_back).

    После отката у эксперта нет активного адаптера; вернуть прежнюю версию
    можно повторной публикацией. Применение на inference-сервере — шаг
    оператора (`scripts/apply-loras.sh`); при `LORA_APPLY_ON_PUBLISH=true` API
    дополнительно уведомляет внешний перезагрузчик модели.
    """
    require_admin(ctx)
    conn = await _conn()
    try:
        async with conn.transaction():
            row = await conn.fetchrow(
                "SELECT * FROM lora_adapters WHERE id = $1 FOR UPDATE",
                adapter_id,
            )
            if not row:
                raise HTTPException(404, "Адаптер не найден.")
            if row["status"] != "published":
                raise HTTPException(
                    409,
                    "Откатить можно только опубликованный адаптер.",
                )

            row = await conn.fetchrow(
                """
                UPDATE lora_adapters
                SET status = 'rolled_back', rolled_back_by = $1, rolled_back_at = NOW()
                WHERE id = $2
                RETURNING *
                """,
                ctx.actor,
                adapter_id,
            )
    finally:
        await conn.close()

    lora_runtime.reset_cache()
    await write_audit(
        actor=ctx.actor,
        action="lora_adapter.rollback",
        resource_type="lora_adapter",
        resource_id=str(adapter_id),
        details={
            "expert": row["expert_key"],
            "version": row["version"],
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None),
    )
    reload = await lora_runtime.request_model_reload(
        reason=f"rollback:{row['expert_key']}",
    )
    return {**_adapter_summary(row), "model_reload": reload}


@router.delete("/{adapter_id}", status_code=204)
async def delete_lora_adapter(
    adapter_id: UUID,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    """Удаляет запись реестра. Опубликованный адаптер удалить нельзя —
    сначала выполните откат."""
    require_admin(ctx)
    conn = await _conn()
    try:
        async with conn.transaction():
            row = await conn.fetchrow(
                "SELECT * FROM lora_adapters WHERE id = $1 FOR UPDATE",
                adapter_id,
            )
            if not row:
                raise HTTPException(404, "Адаптер не найден.")
            if row["status"] == "published":
                raise HTTPException(
                    409,
                    "Нельзя удалить опубликованный адаптер — сначала выполните откат.",
                )

            await conn.execute(
                "DELETE FROM lora_adapters WHERE id = $1",
                adapter_id,
            )
            remaining_refs = await conn.fetchval(
                """
                SELECT COUNT(*) FROM lora_adapters
                WHERE storage_path = $1
                """,
                row["storage_path"],
            )
    finally:
        await conn.close()

    _delete_adapter_file_if_orphan(row["storage_path"], remaining_refs or 0)

    await write_audit(
        actor=ctx.actor,
        action="lora_adapter.delete",
        resource_type="lora_adapter",
        resource_id=str(adapter_id),
        details={
            "expert": row["expert_key"],
            "version": row["version"],
            "sha256": row["file_sha256"],
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None),
    )
