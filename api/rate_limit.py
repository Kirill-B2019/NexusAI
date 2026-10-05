"""
Rate limiting по API-ключу. In-memory sliding window.

Внимание: счётчики в памяти процесса. При горизонтальном
масштабировании (несколько API-инстансов) заменить на Redis.
"""
import time
import logging
from typing import Dict, Tuple
from threading import Lock

logger = logging.getLogger("rate_limit")

_buckets: Dict[str, list] = {}
_lock = Lock()

WINDOW = 60
CLEANUP_INTERVAL = 300
_last_cleanup = time.time()


def _cleanup(now: float):
    """Удаляем устаревшие записи."""
    global _last_cleanup
    if now - _last_cleanup < CLEANUP_INTERVAL:
        return
    with _lock:
        for key in list(_buckets.keys()):
            _buckets[key] = [t for t in _buckets[key] if now - t < WINDOW]
            if not _buckets[key]:
                del _buckets[key]
    _last_cleanup = now


def check_rate_limit(key_id: str, limit: int) -> Tuple[bool, int, int]:
    """
    Проверяет лимит для ключа.
    Возвращает (allowed, remaining, reset_in_seconds).
    """
    now = time.time()
    _cleanup(now)

    with _lock:
        bucket = _buckets.setdefault(key_id, [])
        bucket[:] = [t for t in bucket if now - t < WINDOW]

        if len(bucket) >= limit:
            oldest = bucket[0]
            reset_in = int(WINDOW - (now - oldest)) + 1
            return False, 0, max(reset_in, 1)

        bucket.append(now)
        remaining = max(0, limit - len(bucket))
        return True, remaining, WINDOW


def get_stats() -> dict:
    """Текущее состояние счётчиков (для отладки)."""
    with _lock:
        return {
            "keys": len(_buckets),
            "total_requests": sum(len(v) for v in _buckets.values()),
        }
