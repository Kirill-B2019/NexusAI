"""
NEXUS AI — FastAPI entry point
"""
import os
import logging
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from middleware import RequestIDMiddleware, TimingMiddleware, DocsAuthMiddleware, RateLimitMiddleware
from routers import system, experts, chat, projects, documents, conversations, decisions, tasks, admin, audit, chat_stream

LOG_LEVEL = os.getenv("LOG_LEVEL", "info").upper()
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL, logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("nexus-ai")

app = FastAPI(
    title="NEXUS AI",
    description=(
        "Внутренняя AI-платформа с 6 экспертами и оркестратором.\n\n"
        "**Аутентификация:** `X-API-Key: <key>` или `Authorization: Bearer <key>`.\n\n"
        "**Admin-ключ** — полный доступ. **Project-ключ** — привязан к проекту."
    ),
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
    swagger_ui_parameters={"persistAuthorization": True},
)

CORS_ORIGINS = os.getenv("CORS_ORIGINS", "*")
origins = [o.strip() for o in CORS_ORIGINS.split(",") if o.strip()]
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=False,
    allow_methods=["GET", "POST", "PATCH", "DELETE", "OPTIONS"],
    allow_headers=[
        "X-API-Key",
        "Authorization",
        "Content-Type",
        "X-Request-ID",
        "Idempotency-Key",
    ],
    expose_headers=[
        "X-Request-ID",
        "X-Process-Time-Ms",
        "X-RateLimit-Limit",
        "X-RateLimit-Remaining",
        "X-RateLimit-Reset",
    ],
    max_age=3600,
)

app.add_middleware(RequestIDMiddleware)
app.add_middleware(TimingMiddleware)
app.add_middleware(DocsAuthMiddleware)
app.add_middleware(RateLimitMiddleware)

app.include_router(system.router)
app.include_router(experts.router, prefix="/v1")
app.include_router(chat.router, prefix="/v1")
app.include_router(projects.router, prefix="/v1")
app.include_router(documents.router, prefix="/v1")
app.include_router(conversations.router, prefix="/v1")
app.include_router(decisions.router, prefix="/v1")
app.include_router(tasks.router, prefix="/v1")
app.include_router(admin.router, prefix="/v1")
app.include_router(audit.router, prefix="/v1")
app.include_router(chat_stream.router, prefix="/v1")


@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    req_id = getattr(request.state, "request_id", "unknown")
    logger.exception(f"[{req_id}] Unhandled error: {exc}")
    return JSONResponse(
        status_code=500,
        content={
            "error": {
                "code": "internal_error",
                "message": "Внутренняя ошибка сервера",
                "request_id": req_id,
            }
        },
    )


@app.on_event("startup")
async def startup_event():
    logger.info("NEXUS AI API starting...")
    logger.info(f"CORS origins: {origins}")


@app.on_event("shutdown")
async def shutdown_event():
    logger.info("NEXUS AI API shutting down")
