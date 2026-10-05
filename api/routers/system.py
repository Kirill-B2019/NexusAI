"""System routes: health, version."""
from fastapi import APIRouter, Request

router = APIRouter(tags=["system"])

API_VERSION = "1.0.0"


@router.get("/health")
async def health():
    return {"status": "ok"}


@router.get("/version")
async def version():
    return {
        "api_version": API_VERSION,
        "service": "nexus-ai",
        "models": {
            "chat": "qwen3-4b",
            "embeddings": "intfloat/multilingual-e5-small",
        },
    }
