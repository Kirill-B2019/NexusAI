"""
NEXUS AI Experts Service
Загрузка экспертов из БД с кэшем в памяти.
"""
import asyncpg
import os
import time
from typing import List, Dict, Any, Optional

DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "postgresql://nexusai:nexusai@nexus-postgres:5432/nexusai"
)

_cache: Dict[str, Any] = {"experts": [], "loaded_at": 0}
CACHE_TTL = 60  # сек


async def _fetch_from_db() -> List[Dict[str, Any]]:
    conn = await asyncpg.connect(DATABASE_URL)
    try:
        rows = await conn.fetch("""
            SELECT key, name, description, system_prompt, keywords,
                   is_enabled, is_system, icon, color, sort_order, metadata
            FROM experts
            WHERE deleted_at IS NULL
            ORDER BY sort_order, key
        """)
        return [dict(r) for r in rows]
    finally:
        await conn.close()


async def load_all(force: bool = False) -> List[Dict[str, Any]]:
    """Возвращает всех активных экспертов (is_enabled=true)."""
    now = time.time()
    if force or not _cache["experts"] or now - _cache["loaded_at"] > CACHE_TTL:
        all_experts = await _fetch_from_db()
        _cache["experts"] = [e for e in all_experts if e["is_enabled"]]
        _cache["loaded_at"] = now
    return _cache["experts"]


async def load_all_including_disabled(force: bool = False) -> List[Dict[str, Any]]:
    """Возвращает всех экспертов, включая отключённых (для admin UI)."""
    if force or not _cache["experts"] or time.time() - _cache["loaded_at"] > CACHE_TTL:
        _cache["experts"] = await _fetch_from_db()
        _cache["loaded_at"] = time.time()
    return _cache["experts"]


async def get_prompts() -> Dict[str, str]:
    """Возвращает словарь {key: system_prompt} для активных экспертов."""
    experts = await load_all()
    return {e["key"]: e["system_prompt"] for e in experts}


async def get_keywords() -> Dict[str, List[str]]:
    """Возвращает словарь {key: [keywords]} для активных экспертов."""
    experts = await load_all()
    result = {}
    for e in experts:
        kw = e["keywords"]
        if isinstance(kw, str):
            import json
            kw = json.loads(kw)
        result[e["key"]] = kw or []
    return result


async def get_meta(key: str) -> Optional[Dict[str, Any]]:
    """Метаданные эксперта (name, icon, color) — для UI."""
    experts = await load_all()
    for e in experts:
        if e["key"] == key:
            return {
                "key": e["key"],
                "name": e["name"],
                "description": e["description"],
                "icon": e["icon"],
                "color": e["color"],
            }
    return None


def invalidate():
    """Сброс кэша — вызывать при CRUD экспертов."""
    _cache["experts"] = []
    _cache["loaded_at"] = 0
