"""
NEXUS AI Auth
Проверка API-ключей: admin (из .env) и project (из БД).
"""
import os
import hashlib
import asyncpg
from typing import Optional, List
from fastapi import Header, HTTPException, status
from dataclasses import dataclass

DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "postgresql://nexusai:nexusai@nexus-postgres:5432/nexusai"
)
ADMIN_API_KEY = os.getenv("ADMIN_API_KEY", "")


def hash_key(key: str) -> str:
    return hashlib.sha256(key.encode()).hexdigest()


@dataclass
class AuthContext:
    type: str                       # "admin" | "project"
    project_id: Optional[str] = None
    api_key_id: Optional[str] = None
    key_name: Optional[str] = None
    allowed_experts: Optional[List[str]] = None
    rate_limit_per_min: int = 60
    actor: str = "unknown"          # для audit_log

    @property
    def is_admin(self) -> bool:
        return self.type == "admin"


def _extract_key(authorization: Optional[str], x_api_key: Optional[str]) -> Optional[str]:
    """Достаёт ключ из Authorization: Bearer <key> или X-API-Key."""
    if x_api_key:
        return x_api_key.strip()
    if authorization:
        parts = authorization.split(None, 1)
        if len(parts) == 2 and parts[0].lower() == "bearer":
            return parts[1].strip()
        # Разрешаем просто ключ без префикса
        return authorization.strip()
    return None


async def verify_api_key(
    authorization: Optional[str] = Header(None),
    x_api_key: Optional[str] = Header(None),
) -> AuthContext:
    """
    FastAPI-зависимость: проверяет API-ключ и возвращает AuthContext.
    Выбрасывает 401 при отсутствии/невалидности.
    """
    raw_key = _extract_key(authorization, x_api_key)

    if not raw_key:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="API key required",
            headers={"WWW-Authenticate": "Bearer"},
        )

    # 1. Admin key (plaintext в .env)
    if ADMIN_API_KEY and raw_key == ADMIN_API_KEY:
        return AuthContext(
            type="admin",
            rate_limit_per_min=600,
            actor="admin",
        )

    # 2. Project key — ищем хеш в БД
    key_hash = hash_key(raw_key)
    try:
        conn = await asyncpg.connect(DATABASE_URL)
        try:
            row = await conn.fetchrow("""
                SELECT id, project_id, name, is_active, allowed_experts,
                       rate_limit_per_min, expires_at
                FROM api_keys
                WHERE key_hash = $1
            """, key_hash)
        finally:
            await conn.close()
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Auth DB error: {e}",
        )

    if not row:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key",
            headers={"WWW-Authenticate": "Bearer"},
        )

    if not row["is_active"]:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="API key disabled",
        )

    if row["expires_at"] is not None:
        import datetime
        if row["expires_at"] < datetime.datetime.now(datetime.timezone.utc):
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="API key expired",
            )

    # Обновляем last_used_at асинхронно (не блокируем)
    try:
        conn = await asyncpg.connect(DATABASE_URL)
        try:
            await conn.execute(
                "UPDATE api_keys SET last_used_at = NOW() WHERE id = $1",
                row["id"],
            )
        finally:
            await conn.close()
    except Exception:
        pass

    allowed = None
    if row["allowed_experts"]:
        import json
        if isinstance(row["allowed_experts"], str):
            allowed = json.loads(row["allowed_experts"])
        else:
            allowed = list(row["allowed_experts"])

    return AuthContext(
        type="project",
        project_id=str(row["project_id"]) if row["project_id"] else None,
        api_key_id=str(row["id"]),
        key_name=row["name"],
        allowed_experts=allowed,
        rate_limit_per_min=row["rate_limit_per_min"] or 60,
        actor=f"project:{row['project_id']}" if row["project_id"] else "project",
    )


def require_admin(ctx: AuthContext) -> None:
    """Проверка: только admin-ключ."""
    if not ctx.is_admin:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Admin access required",
        )


def require_project_access(ctx: AuthContext, project_id: str) -> None:
    """Проверка: admin ИЛИ ключ принадлежит этому проекту."""
    if ctx.is_admin:
        return
    if ctx.type == "project" and ctx.project_id == project_id:
        return
    raise HTTPException(
        status_code=status.HTTP_403_FORBIDDEN,
        detail="Access denied to this project",
    )


def filter_experts(ctx: AuthContext, experts: List[str]) -> List[str]:
    """Фильтрует список экспертов с учётом allowed_experts ключа."""
    if ctx.is_admin or not ctx.allowed_experts:
        return experts
    allowed_set = set(ctx.allowed_experts)
    return [e for e in experts if e in allowed_set]


def generate_api_key() -> str:
    """Генерирует новый project API-ключ. Возвращает plaintext."""
    import secrets
    return "nx_" + secrets.token_urlsafe(32)
