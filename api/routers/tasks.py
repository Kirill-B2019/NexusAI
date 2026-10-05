"""Tasks CRUD."""
import asyncpg
import os
import json
from datetime import date
from typing import Optional, List, Dict, Any
from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, Field

from auth import verify_api_key, AuthContext, require_project_access
from middleware import write_audit

DATABASE_URL = os.getenv("DATABASE_URL")

router = APIRouter(tags=["tasks"])

VALID_STATUSES = {"open", "in_progress", "done", "cancelled"}
VALID_PRIORITIES = {"low", "normal", "high", "urgent"}


class TaskCreate(BaseModel):
    title: str = Field(..., min_length=2, max_length=300)
    description: Optional[str] = None
    status: str = "open"
    priority: str = "normal"
    assignee_expert: Optional[str] = None
    source_message_id: Optional[str] = None
    due_date: Optional[str] = None  # ISO YYYY-MM-DD
    external_id: Optional[str] = None
    metadata: Dict[str, Any] = {}


class TaskUpdate(BaseModel):
    title: Optional[str] = Field(None, min_length=2, max_length=300)
    description: Optional[str] = None
    status: Optional[str] = None
    priority: Optional[str] = None
    assignee_expert: Optional[str] = None
    due_date: Optional[str] = None
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
        "description": row["description"],
        "status": row["status"],
        "priority": row["priority"],
        "assignee_expert": row["assignee_expert"],
        "source_message_id": str(row["source_message_id"]) if row["source_message_id"] else None,
        "due_date": row["due_date"].isoformat() if row["due_date"] else None,
        "metadata": meta or {},
        "created_at": row["created_at"].isoformat() if row["created_at"] else None,
        "updated_at": row["updated_at"].isoformat() if row["updated_at"] else None,
    }


async def _conn():
    return await asyncpg.connect(DATABASE_URL)


@router.get("/projects/{project_id}/tasks")
async def list_tasks(
    project_id: str,
    status: Optional[str] = Query(None),
    priority: Optional[str] = Query(None),
    assignee_expert: Optional[str] = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)

    # Динамические фильтры
    conditions = ["project_id = $1"]
    params: List[Any] = [project_id]
    idx = 2

    if status:
        conditions.append(f"status = ${idx}")
        params.append(status)
        idx += 1
    if priority:
        conditions.append(f"priority = ${idx}")
        params.append(priority)
        idx += 1
    if assignee_expert:
        conditions.append(f"assignee_expert = ${idx}")
        params.append(assignee_expert)
        idx += 1

    where = " AND ".join(conditions)

    conn = await _conn()
    try:
        rows = await conn.fetch(
            f"SELECT * FROM tasks WHERE {where} "
            f"ORDER BY "
            f"  CASE priority "
            f"    WHEN 'urgent' THEN 1 "
            f"    WHEN 'high' THEN 2 "
            f"    WHEN 'normal' THEN 3 "
            f"    WHEN 'low' THEN 4 "
            f"    ELSE 5 END, "
            f"  created_at DESC "
            f"LIMIT ${idx} OFFSET ${idx + 1}",
            *params, limit, offset,
        )
        total = await conn.fetchval(
            f"SELECT COUNT(*) FROM tasks WHERE {where}", *params
        )
    finally:
        await conn.close()

    return {
        "tasks": [_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@router.post("/projects/{project_id}/tasks", status_code=201)
async def create_task(
    project_id: str,
    payload: TaskCreate,
    ctx: AuthContext = Depends(verify_api_key),
):
    require_project_access(ctx, project_id)

    if payload.status not in VALID_STATUSES:
        raise HTTPException(400, f"Invalid status. Allowed: {VALID_STATUSES}")
    if payload.priority not in VALID_PRIORITIES:
        raise HTTPException(400, f"Invalid priority. Allowed: {VALID_PRIORITIES}")

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

        due = None
        if payload.due_date:
            try:
                due = date.fromisoformat(payload.due_date)
            except ValueError:
                raise HTTPException(400, "due_date must be YYYY-MM-DD")

        row = await conn.fetchrow("""
            INSERT INTO tasks
                (project_id, title, description, status, priority,
                 assignee_expert, source_message_id, due_date,
                 external_id, metadata)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10::jsonb)
            RETURNING *
        """, project_id, payload.title, payload.description,
            payload.status, payload.priority, payload.assignee_expert,
            payload.source_message_id, due,
            payload.external_id, json.dumps(payload.metadata))
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="task.create",
        resource_type="task", resource_id=str(row["id"]),
        project_id=project_id,
        details={"title": payload.title, "priority": payload.priority},
    )
    return _to_dict(row)


@router.get("/tasks/{task_id}")
async def get_task(task_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM tasks WHERE id = $1", task_id)
        if not row:
            raise HTTPException(404, "Task not found")
        require_project_access(ctx, str(row["project_id"]))
    finally:
        await conn.close()

    return _to_dict(row)


@router.patch("/tasks/{task_id}")
async def update_task(
    task_id: str,
    payload: TaskUpdate,
    ctx: AuthContext = Depends(verify_api_key),
):
    conn = await _conn()
    try:
        row = await conn.fetchrow("SELECT * FROM tasks WHERE id = $1", task_id)
        if not row:
            raise HTTPException(404, "Task not found")
        require_project_access(ctx, str(row["project_id"]))

        upd = payload.dict(exclude_unset=True)
        if "status" in upd and upd["status"] not in VALID_STATUSES:
            raise HTTPException(400, f"Invalid status. Allowed: {VALID_STATUSES}")
        if "priority" in upd and upd["priority"] not in VALID_PRIORITIES:
            raise HTTPException(400, f"Invalid priority. Allowed: {VALID_PRIORITIES}")

        fields = []
        values = []
        idx = 1
        for k, v in upd.items():
            if k == "metadata":
                fields.append(f"{k} = ${idx}::jsonb")
                values.append(json.dumps(v))
            elif k == "due_date":
                if v is None:
                    fields.append(f"{k} = NULL")
                else:
                    try:
                        d = date.fromisoformat(v)
                    except ValueError:
                        raise HTTPException(400, "due_date must be YYYY-MM-DD")
                    fields.append(f"{k} = ${idx}")
                    values.append(d)
                    idx += 1
                    continue
            else:
                fields.append(f"{k} = ${idx}")
                values.append(v)
            idx += 1

        if not fields:
            return _to_dict(row)

        values.append(task_id)
        sql = f"UPDATE tasks SET {', '.join(fields)} WHERE id = ${idx} RETURNING *"
        row = await conn.fetchrow(sql, *values)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="task.update",
        resource_type="task", resource_id=task_id,
        details={"fields": list(upd.keys())},
    )
    return _to_dict(row)


@router.delete("/tasks/{task_id}", status_code=204)
async def delete_task(task_id: str, ctx: AuthContext = Depends(verify_api_key)):
    conn = await _conn()
    try:
        row = await conn.fetchrow(
            "SELECT project_id FROM tasks WHERE id = $1", task_id
        )
        if not row:
            raise HTTPException(404, "Task not found")
        require_project_access(ctx, str(row["project_id"]))
        await conn.execute("DELETE FROM tasks WHERE id = $1", task_id)
    finally:
        await conn.close()

    await write_audit(
        actor=ctx.actor, action="task.delete",
        resource_type="task", resource_id=task_id,
    )
