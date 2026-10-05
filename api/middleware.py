"""
NEXUS AI Middleware
- X-Request-ID
- Audit log для критичных операций
"""
import uuid
import time
import asyncpg
import os
from typing import Callable
from fastapi import Request, Response
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware

DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "postgresql://nexusai:nexusai@nexus-postgres:5432/nexusai"
)


class RequestIDMiddleware(BaseHTTPMiddleware):
    """Добавляет X-Request-ID в каждый ответ."""
    async def dispatch(self, request: Request, call_next: Callable) -> Response:
        req_id = request.headers.get("X-Request-ID") or f"req_{uuid.uuid4().hex[:16]}"
        request.state.request_id = req_id
        response = await call_next(request)
        response.headers["X-Request-ID"] = req_id
        return response


class TimingMiddleware(BaseHTTPMiddleware):
    """Добавляет X-Process-Time в каждый ответ."""
    async def dispatch(self, request: Request, call_next: Callable) -> Response:
        start = time.time()
        response = await call_next(request)
        elapsed = (time.time() - start) * 1000
        response.headers["X-Process-Time-Ms"] = f"{elapsed:.1f}"
        return response


async def write_audit(
    actor: str,
    action: str,
    resource_type: str = None,
    resource_id: str = None,
    project_id: str = None,
    api_key_id: str = None,
    details: dict = None,
    ip: str = None,
    user_agent: str = None,
    request_id: str = None,
) -> None:
    """Пишет запись в audit_log. Не бросает исключений."""
    try:
        conn = await asyncpg.connect(DATABASE_URL)
        try:
            await conn.execute("""
                INSERT INTO audit_log
                    (actor, action, resource_type, resource_id,
                     project_id, api_key_id, details, ip, user_agent, request_id)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
            """, actor, action, resource_type, resource_id,
                project_id, api_key_id,
                details and __import__("json").dumps(details),
                ip, user_agent, request_id)
        finally:
            await conn.close()
    except Exception:
        # Аудит не должен ломать основной запрос
        pass


# ─── Защита /docs, /redoc, /openapi.json ───────────────────
DOCS_PATHS = ("/docs", "/redoc", "/openapi.json")


class DocsAuthMiddleware(BaseHTTPMiddleware):
    """
    Пропускает к /docs, /redoc, /openapi.json только admin-ключ.
    """
    async def dispatch(self, request: Request, call_next):
        path = request.url.path
        if not any(path.startswith(p) for p in DOCS_PATHS):
            return await call_next(request)

        admin_key = os.getenv("ADMIN_API_KEY", "")
        if not admin_key:
            return JSONResponse(
                status_code=500,
                content={"detail": "ADMIN_API_KEY not configured"},
            )

        # Ключ из X-API-Key или Authorization
        provided = request.headers.get("X-API-Key", "").strip()
        if not provided:
            auth = request.headers.get("Authorization", "")
            if auth.lower().startswith("bearer "):
                provided = auth[7:].strip()

        # Для /docs и /redoc Swagger UI подгружает /openapi.json — с параметром ?api_key=... или через cookie
        # Используем заголовок
        if provided != admin_key:
            return JSONResponse(
                status_code=401,
                content={"detail": "Admin API key required for documentation"},
            )

        return await call_next(request)


# ─── Rate limiting middleware ──────────────────────────────
from rate_limit import check_rate_limit
import hashlib as _hashlib


RATE_LIMIT_EXEMPT = ("/health", "/version", "/docs", "/redoc", "/openapi.json")


class RateLimitMiddleware(BaseHTTPMiddleware):
    """
    Проверяет лимит по API-ключу.
    Лимиты: admin = 600/мин, project = 60/мин (жёстко закодировано).
    """
    async def dispatch(self, request: Request, call_next):
        path = request.url.path

        if any(path.startswith(p) for p in RATE_LIMIT_EXEMPT):
            return await call_next(request)

        raw_key = request.headers.get("X-API-Key", "").strip()
        if not raw_key:
            auth = request.headers.get("Authorization", "")
            if auth.lower().startswith("bearer "):
                raw_key = auth[7:].strip()

        if not raw_key:
            return await call_next(request)

        admin_key = os.getenv("ADMIN_API_KEY", "")
        if admin_key and raw_key == admin_key:
            key_id = "admin"
            limit = 600
        else:
            key_id = "k_" + _hashlib.sha256(raw_key.encode()).hexdigest()[:16]
            limit = 60

        allowed, remaining, reset_in = check_rate_limit(key_id, limit)

        if not allowed:
            return JSONResponse(
                status_code=429,
                content={"detail": "Rate limit exceeded. Try again later."},
                headers={
                    "X-RateLimit-Limit": str(limit),
                    "X-RateLimit-Remaining": "0",
                    "X-RateLimit-Reset": str(reset_in),
                    "Retry-After": str(reset_in),
                },
            )

        response = await call_next(request)
        response.headers["X-RateLimit-Limit"] = str(limit)
        response.headers["X-RateLimit-Remaining"] = str(remaining)
        return response
