"""Административные операции с наборами Q/A для обучения экспертов."""
import hashlib
import asyncpg
import json
import os
from typing import Any
from uuid import UUID

from fastapi import APIRouter, Body, Depends, File, Form, HTTPException, Path, Request, UploadFile

from auth import AuthContext, require_admin, verify_api_key
from dataset_validation import (
    DUPLICATE_THRESHOLD,
    FORMAT_VERSION,
    MAX_DATASET_BYTES,
    find_cross_batch_duplicates,
    recompute_report,
    validate_dataset,
)
from middleware import request_ip, request_user_agent, write_audit
from pydantic import BaseModel, Field
from training_topics import coverage_report, expert_topics, target_total

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(prefix="/admin/training/datasets", tags=["training-datasets"])


class TrainingBatchReview(BaseModel):
    duplicates_reviewed: bool = False


class TrainingReleaseCreate(BaseModel):
    expert: str = Field(..., min_length=1, max_length=100)


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


def _json_value(value: Any) -> Any:
    return json.loads(value) if isinstance(value, str) else value


def _batch_summary(row) -> dict[str, Any]:
    return {
        "id": str(row["id"]),
        "expert": row["expert_key"],
        "filename": row["original_filename"],
        "sha256": row["source_sha256"],
        "status": row["status"],
        "total": row["total_count"],
        "valid": row["valid_count"],
        "invalid": row["invalid_count"],
        "duplicates": row["duplicate_count"],
        "created_by": row["created_by"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "reviewed_by": row["reviewed_by"],
        "reviewed_at": row["reviewed_at"].isoformat() if row["reviewed_at"] else None,
    }


def _apply_expert_validation(
    result: dict[str, Any],
    expert: str,
    existing_questions: list[dict[str, Any]],
) -> dict[str, Any]:
    for example in result["examples"]:
        declared_expert = example["metadata"].get("expert")
        if declared_expert is not None and declared_expert != expert:
            result["errors"].append({
                "row": example["row"],
                "status": "invalid",
                "message": "Эксперт в metadata не совпадает с выбранным экспертом.",
            })
            example["valid"] = False
            example["status"] = "invalid"
        else:
            example["valid"] = True

    result["duplicates"].extend(
        find_cross_batch_duplicates(result["examples"], existing_questions)
    )
    duplicate_rows = {duplicate["row"] for duplicate in result["duplicates"]}
    for example in result["examples"]:
        if example["valid"] and example["row"] in duplicate_rows:
            example["status"] = "duplicate_review"
    result["invalid"] = result["total"] - sum(
        1 for example in result["examples"] if example["valid"]
    )
    result["valid"] = result["total"] - result["invalid"]
    return result


async def _ensure_expert_exists(conn, expert: str) -> None:
    expert_exists = await conn.fetchval(
        "SELECT 1 FROM experts WHERE key = $1 AND deleted_at IS NULL",
        expert,
    )
    if not expert_exists:
        raise HTTPException(404, "Эксперт не найден.")


async def _load_existing_questions(
    conn,
    expert: str,
    exclude_batch_id: UUID | None = None,
) -> list[dict[str, Any]]:
    rows = await conn.fetch(
        """
        SELECT batch.id AS batch_id,
               example->>'row' AS example_row,
               example->>'question' AS question
        FROM training_dataset_batches AS batch
        CROSS JOIN LATERAL jsonb_array_elements(
            batch.validation_report->'examples'
        ) AS examples(example)
        WHERE batch.expert_key = $1
          AND batch.status IN ('pending_review', 'approved')
          AND ($2::uuid IS NULL OR batch.id <> $2)
          AND COALESCE((example->>'valid')::boolean, TRUE)
        ORDER BY batch.created_at DESC
        """,
        expert,
        exclude_batch_id,
    )
    return [
        {
            "batch_id": row["batch_id"],
            "row": int(row["example_row"]),
            "question": row["question"],
        }
        for row in rows
        if row["question"]
    ]


async def _load_expert_and_existing_questions(conn, expert: str):
    await _ensure_expert_exists(conn, expert)
    return await _load_existing_questions(conn, expert)


async def _find_idempotent_batch(conn, expert: str, source_sha256: str):
    return await conn.fetchrow(
        """
        SELECT * FROM training_dataset_batches
        WHERE expert_key = $1
          AND source_sha256 = $2
          AND status IN ('pending_review', 'approved')
        ORDER BY created_at DESC
        LIMIT 1
        """,
        expert,
        source_sha256,
    )


@router.get("/stats")
async def training_dataset_stats(
    expert: str,
    ctx: AuthContext = Depends(verify_api_key),
):
    """Покрытие тем по утверждённым парам эксперта."""
    require_admin(ctx)
    expert = (expert or "").strip()
    if not expert:
        raise HTTPException(422, "Укажите эксперта.")

    conn = await _conn()
    try:
        await _ensure_expert_exists(conn, expert)
        rows = await conn.fetch(
            """
            SELECT snap.examples
            FROM training_dataset_snapshots AS snap
            JOIN training_dataset_batches AS batch ON batch.id = snap.batch_id
            WHERE snap.expert_key = $1 AND batch.status = 'approved'
            """,
            expert,
        )
    finally:
        await conn.close()

    examples: list[dict[str, Any]] = []
    for row in rows:
        examples.extend(_json_value(row["examples"]) or [])

    topics = expert_topics(expert)
    if topics:
        report = coverage_report(examples, topics)
        target = target_total(topics)
        missing = sorted(
            (item for item in report if item["missing"] > 0),
            key=lambda item: (-item["missing"], item["key"]),
        )
        missing_topics = [item["key"] for item in missing]
    else:
        report = []
        target = None
        missing_topics = []

    return {
        "expert": expert,
        "approved_examples": len(examples),
        "target": target,
        "topics": report,
        "missing_topics": missing_topics,
    }


@router.post("/validate")
async def validate_training_dataset(
    request: Request,
    expert: str = Form(..., min_length=1, max_length=100),
    file: UploadFile = File(...),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)

    if not file.filename:
        raise HTTPException(422, "Имя файла не указано.")

    content = await file.read(MAX_DATASET_BYTES + 1)
    if len(content) > MAX_DATASET_BYTES:
        raise HTTPException(
            413,
            f"Файл превышает ограничение {MAX_DATASET_BYTES // (1024 * 1024)} МБ.",
        )

    try:
        result = validate_dataset(file.filename, content)
    except ValueError as exception:
        raise HTTPException(422, str(exception)) from exception

    conn = await _conn()
    try:
        existing_questions = await _load_expert_and_existing_questions(conn, expert)
    finally:
        await conn.close()

    result = _apply_expert_validation(result, expert, existing_questions)
    result["expert"] = expert

    await write_audit(
        actor=ctx.actor,
        action="training_dataset.validate",
        resource_type="training_dataset",
        details={
            "expert": expert,
            "filename": file.filename[:255],
            "source_sha256": hashlib.sha256(content).hexdigest(),
            "total": result["total"],
            "valid": result["valid"],
            "invalid": result["invalid"],
            "duplicates": len(result["duplicates"]),
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None),
    )
    return result


@router.post("/batches", status_code=201)
async def create_training_batch(
    request: Request,
    expert: str = Form(..., min_length=1, max_length=100),
    file: UploadFile = File(...),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)

    if not file.filename:
        raise HTTPException(422, "Имя файла не указано.")

    content = await file.read(MAX_DATASET_BYTES + 1)
    if len(content) > MAX_DATASET_BYTES:
        raise HTTPException(
            413,
            f"Файл превышает ограничение {MAX_DATASET_BYTES // (1024 * 1024)} МБ.",
        )

    try:
        result = validate_dataset(file.filename, content)
    except ValueError as exception:
        raise HTTPException(422, str(exception)) from exception

    source_sha256 = hashlib.sha256(content).hexdigest()
    reused = False

    conn = await _conn()
    try:
        async with conn.transaction():
            await _ensure_expert_exists(conn, expert)

            existing = await _find_idempotent_batch(conn, expert, source_sha256)
            if existing:
                row = existing
                reused = True
            else:
                existing_questions = await _load_existing_questions(conn, expert)
                result = _apply_expert_validation(result, expert, existing_questions)

                try:
                    row = await conn.fetchrow(
                        """
                        INSERT INTO training_dataset_batches
                            (expert_key, original_filename, source_sha256, status,
                             total_count, valid_count, invalid_count, duplicate_count,
                             validation_report, created_by)
                        VALUES ($1, $2, $3, 'pending_review', $4, $5, $6, $7, $8::jsonb, $9)
                        RETURNING *
                        """,
                        expert,
                        file.filename[:255],
                        source_sha256,
                        result["total"],
                        result["valid"],
                        result["invalid"],
                        len(result["duplicates"]),
                        json.dumps(result, ensure_ascii=False),
                        ctx.actor,
                    )
                except asyncpg.UniqueViolationError:
                    # Параллельная загрузка того же файла уже создала партию.
                    row = await _find_idempotent_batch(conn, expert, source_sha256)
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
            action="training_dataset.batch.reuse",
            resource_type="training_dataset_batch",
            resource_id=str(row["id"]),
            details={
                "expert": expert,
                "filename": file.filename[:255],
                "source_sha256": source_sha256,
            },
            **audit_meta,
        )
    else:
        await write_audit(
            actor=ctx.actor,
            action="training_dataset.batch.create",
            resource_type="training_dataset_batch",
            resource_id=str(row["id"]),
            details={
                "expert": expert,
                "filename": file.filename[:255],
                "source_sha256": source_sha256,
                "total": result["total"],
                "valid": result["valid"],
                "invalid": result["invalid"],
                "duplicates": len(result["duplicates"]),
            },
            **audit_meta,
        )

    return {
        **_batch_summary(row),
        "validation": _json_value(row["validation_report"]),
        "reused": reused,
    }


@router.get("/batches")
async def list_training_batches(
    expert: str | None = None,
    status: str | None = None,
    limit: int = 50,
    offset: int = 0,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    if not 1 <= limit <= 100 or offset < 0:
        raise HTTPException(422, "limit должен быть 1–100, offset — неотрицательным.")
    if status and status not in {"pending_review", "approved", "rejected"}:
        raise HTTPException(422, "Недопустимый статус партии.")

    conn = await _conn()
    try:
        rows = await conn.fetch(
            """
            SELECT * FROM training_dataset_batches
            WHERE ($1::text IS NULL OR expert_key = $1)
              AND ($2::text IS NULL OR status = $2)
            ORDER BY created_at DESC
            LIMIT $3 OFFSET $4
            """,
            expert, status, limit, offset,
        )
        total = await conn.fetchval(
            """
            SELECT COUNT(*) FROM training_dataset_batches
            WHERE ($1::text IS NULL OR expert_key = $1)
              AND ($2::text IS NULL OR status = $2)
            """,
            expert, status,
        )
    finally:
        await conn.close()

    return {
        "batches": [_batch_summary(row) for row in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.get("/batches/{batch_id}")
async def get_training_batch(
    batch_id: UUID,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM training_dataset_batches WHERE id = $1",
            batch_id,
        )
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Партия не найдена.")

    return {
        **_batch_summary(row),
        "validation": _json_value(row["validation_report"]),
    }


async def _review_training_batch(
    batch_id: UUID,
    decision: str,
    duplicates_reviewed: bool,
    ctx: AuthContext,
    request: Request | None = None,
):
    require_admin(ctx)
    conn = await _conn()
    try:
        async with conn.transaction():
            row = await conn.fetchrow(
                "SELECT * FROM training_dataset_batches WHERE id = $1 FOR UPDATE",
                batch_id,
            )
            if not row:
                raise HTTPException(404, "Партия не найдена.")
            if row["status"] != "pending_review":
                raise HTTPException(409, "Партия уже рассмотрена.")
            if decision == "approved" and row["invalid_count"] > 0:
                raise HTTPException(
                    409,
                    "Нельзя утвердить партию: исправьте или удалите некорректные строки.",
                )
            if (
                decision == "approved"
                and row["duplicate_count"] > 0
                and not duplicates_reviewed
            ):
                raise HTTPException(
                    409,
                    "Подтвердите ручную проверку возможных дублей перед утверждением.",
                )

            reviewed = await conn.fetchrow(
                """
                UPDATE training_dataset_batches
                SET status = $1, reviewed_by = $2, reviewed_at = NOW()
                WHERE id = $3
                RETURNING *
                """,
                decision, ctx.actor, batch_id,
            )

            if decision == "approved":
                validation = _json_value(row["validation_report"])
                approved_examples = [
                    example
                    for example in validation["examples"]
                    if example.get("valid")
                ]
                await conn.execute(
                    """
                    INSERT INTO training_dataset_snapshots
                        (batch_id, expert_key, examples, source_sha256, created_by)
                    VALUES ($1, $2, $3::jsonb, $4, $5)
                    """,
                    batch_id,
                    row["expert_key"],
                    json.dumps(approved_examples, ensure_ascii=False),
                    row["source_sha256"],
                    ctx.actor,
                )
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor,
        action=f"training_dataset.batch.{decision}",
        resource_type="training_dataset_batch",
        resource_id=str(batch_id),
        details={
            "expert": row["expert_key"],
            "source_sha256": row["source_sha256"],
            "valid": row["valid_count"],
            "invalid": row["invalid_count"],
            "duplicates": row["duplicate_count"],
            "duplicates_reviewed": duplicates_reviewed,
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None) if request else None,
    )
    return _batch_summary(reviewed)


@router.post("/batches/{batch_id}/approve")
async def approve_training_batch(
    batch_id: UUID,
    payload: TrainingBatchReview,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    return await _review_training_batch(
        batch_id,
        "approved",
        payload.duplicates_reviewed,
        ctx,
        request,
    )


@router.post("/batches/{batch_id}/reject")
async def reject_training_batch(
    batch_id: UUID,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    return await _review_training_batch(batch_id, "rejected", False, ctx, request)


def _release_summary(row) -> dict[str, Any]:
    return {
        "id": str(row["id"]),
        "expert": row["expert_key"],
        "batch_ids": _json_value(row["batch_ids"]),
        "example_count": row["example_count"],
        "sha256": row["source_sha256"],
        "format_version": row["format_version"],
        "prep_params": _json_value(row["prep_params"]),
        "created_by": row["created_by"],
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
    }


@router.post("/releases", status_code=201)
async def create_training_release(
    payload: TrainingReleaseCreate,
    request: Request,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    expert = payload.expert

    conn = await _conn()
    try:
        async with conn.transaction():
            await _ensure_expert_exists(conn, expert)

            snapshots = await conn.fetch(
                """
                SELECT snap.id AS snapshot_id,
                       snap.batch_id,
                       snap.examples
                FROM training_dataset_snapshots AS snap
                JOIN training_dataset_batches AS batch
                    ON batch.id = snap.batch_id
                WHERE snap.expert_key = $1
                  AND batch.status = 'approved'
                ORDER BY batch.created_at ASC, snap.created_at ASC
                """,
                expert,
            )
            if not snapshots:
                raise HTTPException(
                    409,
                    "Для эксперта нет утверждённых партий с snapshot.",
                )

            batch_ids = [str(row["batch_id"]) for row in snapshots]
            examples: list[dict[str, Any]] = []
            for row in snapshots:
                examples.extend(_json_value(row["examples"]))

            if not examples:
                raise HTTPException(
                    409,
                    "В утверждённых партиях эксперта нет примеров.",
                )

            source_sha256 = hashlib.sha256(
                json.dumps(examples, ensure_ascii=False, sort_keys=True).encode(),
            ).hexdigest()

            prep_params = {
                "duplicate_threshold": DUPLICATE_THRESHOLD,
                "ordering": "batch_created_at",
                "source": "approved_snapshots",
            }

            row = await conn.fetchrow(
                """
                INSERT INTO training_dataset_releases
                    (expert_key, batch_ids, example_count, source_sha256,
                     format_version, prep_params, examples, created_by)
                VALUES ($1, $2::jsonb, $3, $4, $5, $6::jsonb, $7::jsonb, $8)
                RETURNING *
                """,
                expert,
                json.dumps(batch_ids, ensure_ascii=False),
                len(examples),
                source_sha256,
                FORMAT_VERSION,
                json.dumps(prep_params, ensure_ascii=False),
                json.dumps(examples, ensure_ascii=False),
                ctx.actor,
            )
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor,
        action="training_dataset.release.create",
        resource_type="training_dataset_release",
        resource_id=str(row["id"]),
        details={
            "expert": expert,
            "batch_ids": batch_ids,
            "example_count": len(examples),
            "source_sha256": source_sha256,
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None),
    )

    return _release_summary(row)


@router.get("/releases")
async def list_training_releases(
    expert: str | None = None,
    limit: int = 50,
    offset: int = 0,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    if not 1 <= limit <= 100 or offset < 0:
        raise HTTPException(422, "limit должен быть 1–100, offset — неотрицательным.")

    conn = await _conn()
    try:
        rows = await conn.fetch(
            """
            SELECT * FROM training_dataset_releases
            WHERE ($1::text IS NULL OR expert_key = $1)
            ORDER BY created_at DESC
            LIMIT $2 OFFSET $3
            """,
            expert, limit, offset,
        )
        total = await conn.fetchval(
            """
            SELECT COUNT(*) FROM training_dataset_releases
            WHERE ($1::text IS NULL OR expert_key = $1)
            """,
            expert,
        )
    finally:
        await conn.close()

    return {
        "releases": [_release_summary(row) for row in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.get("/releases/{release_id}")
async def get_training_release(
    release_id: UUID,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_admin(ctx)
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT * FROM training_dataset_releases WHERE id = $1",
            release_id,
        )
    finally:
        await conn.close()

    if not row:
        raise HTTPException(404, "Снимок датасета не найден.")

    return {
        **_release_summary(row),
        "examples": _json_value(row["examples"]),
    }


class TrainingExampleUpdate(BaseModel):
    question: str | None = Field(None, max_length=10000)
    answer: str | None = Field(None, max_length=100000)
    metadata: dict[str, Any] | None = None


def _clean_text(value: str | None) -> str | None:
    if value is None:
        return None
    cleaned = value.strip()
    if not cleaned:
        raise HTTPException(422, "Вопрос и ответ не должны быть пустыми.")
    return cleaned


async def _pending_batch(conn, batch_id: UUID):
    row = await conn.fetchrow(
        "SELECT * FROM training_dataset_batches WHERE id = $1 FOR UPDATE",
        batch_id,
    )
    if not row:
        raise HTTPException(404, "Партия не найдена.")
    if row["status"] != "pending_review":
        raise HTTPException(409, "Изменять можно только партию со статусом «ожидает проверки».")
    return row


async def _mutate_example(
    batch_id: UUID,
    row_number: int,
    payload: TrainingExampleUpdate | None,
    ctx: AuthContext,
    action: str,
    request: Request | None = None,
):
    """Правит или удаляет строку черновика и пересчитывает отчёт партии."""
    require_admin(ctx)
    conn = await _conn()
    try:
        async with conn.transaction():
            batch = await _pending_batch(conn, batch_id)
            report = _json_value(batch["validation_report"])
            examples = report.get("examples", [])
            example = next(
                (item for item in examples if item.get("row") == row_number),
                None,
            )
            error = next(
                (item for item in report.get("errors", []) if item.get("row") == row_number),
                None,
            )
            if example is None and error is None:
                raise HTTPException(404, "Строка не найдена в этой партии.")

            if payload is None:
                # Удаление: строка исчезает и из примеров, и из ошибок разбора.
                report["examples"] = [
                    item for item in examples if item.get("row") != row_number
                ]
                report["errors"] = [
                    item
                    for item in report.get("errors", [])
                    if item.get("row") != row_number
                ]
            elif example is None:
                # Строка была некорректной при разборе: контент неизвестен,
                # поэтому правка требует полного вопроса и ответа.
                question = _clean_text(payload.question)
                answer = _clean_text(payload.answer)
                if question is None or answer is None:
                    raise HTTPException(
                        422,
                        "Для некорректной строки укажите question и answer целиком.",
                    )
                report["examples"].append({
                    "row": row_number,
                    "question": question,
                    "answer": answer,
                    "metadata": payload.metadata or {},
                    "source_format": "edited",
                    "valid": True,
                })
                report["examples"].sort(key=lambda item: item["row"])
                report["errors"] = [
                    item
                    for item in report.get("errors", [])
                    if item.get("row") != row_number
                ]
            else:
                question = _clean_text(payload.question)
                answer = _clean_text(payload.answer)
                if question is not None:
                    example["question"] = question
                if answer is not None:
                    example["answer"] = answer
                if payload.metadata is not None:
                    example["metadata"] = payload.metadata

            existing_questions = await _load_existing_questions(
                conn,
                batch["expert_key"],
                exclude_batch_id=batch_id,
            )
            report = recompute_report(report, batch["expert_key"], existing_questions)

            if report["total"] == 0:
                raise HTTPException(
                    409,
                    "Нельзя удалить все строки партии — загрузите исправленный файл.",
                )

            reviewed = await conn.fetchrow(
                """
                UPDATE training_dataset_batches
                SET validation_report = $1::jsonb,
                    total_count = $2,
                    valid_count = $3,
                    invalid_count = $4,
                    duplicate_count = $5
                WHERE id = $6
                RETURNING *
                """,
                json.dumps(report, ensure_ascii=False),
                report["total"],
                report["valid"],
                report["invalid"],
                len(report["duplicates"]),
                batch_id,
            )
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor,
        action=f"training_dataset.example.{action}",
        resource_type="training_dataset_batch",
        resource_id=str(batch_id),
        details={
            "expert": batch["expert_key"],
            "row": row_number,
            "total": report["total"],
            "valid": report["valid"],
            "invalid": report["invalid"],
            "duplicates": len(report["duplicates"]),
        },
        ip=request_ip(request),
        user_agent=request_user_agent(request),
        request_id=getattr(request.state, "request_id", None) if request else None,
    )

    return {
        **_batch_summary(reviewed),
        "validation": report,
    }


@router.patch("/batches/{batch_id}/examples/{row}")
async def update_training_example(
    batch_id: UUID,
    payload: TrainingExampleUpdate,
    request: Request,
    row_number: int = Path(..., alias="row", ge=1),
    ctx: AuthContext = Depends(verify_api_key),
):
    if (
        payload.question is None
        and payload.answer is None
        and payload.metadata is None
    ):
        raise HTTPException(422, "Укажите хотя бы одно поле: question, answer или metadata.")

    return await _mutate_example(batch_id, row_number, payload, ctx, "update", request)


@router.delete("/batches/{batch_id}/examples/{row}")
async def delete_training_example(
    batch_id: UUID,
    request: Request,
    row_number: int = Path(..., alias="row", ge=1),
    ctx: AuthContext = Depends(verify_api_key),
):
    return await _mutate_example(batch_id, row_number, None, ctx, "delete", request)