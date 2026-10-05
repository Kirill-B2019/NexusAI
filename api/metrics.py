"""
Prometheus-метрики для NEXUS AI API.
"""
import time
from prometheus_client import (
    Counter, Histogram, Gauge, generate_latest, CONTENT_TYPE_LATEST
)
from fastapi import Request, Response
from starlette.middleware.base import BaseHTTPMiddleware


# ─── Метрики ──────────────────────────────────────────────
http_requests_total = Counter(
    "nexus_http_requests_total",
    "Всего HTTP-запросов",
    ["method", "endpoint", "status"],
)

http_request_duration = Histogram(
    "nexus_http_request_duration_seconds",
    "Длительность HTTP-запросов",
    ["method", "endpoint"],
    buckets=[0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300],
)

http_requests_in_progress = Gauge(
    "nexus_http_requests_in_progress",
    "Активные HTTP-запросы",
    ["method"],
)

chat_requests_total = Counter(
    "nexus_chat_requests_total",
    "Всего запросов к /v1/chat",
    ["mode"],  # single, auto_orchestration, manual_orchestration
)

chat_experts_used = Histogram(
    "nexus_chat_experts_used",
    "Сколько экспертов задействовано на запрос",
    buckets=[1, 2, 3, 4, 5, 6],
)

document_uploads_total = Counter(
    "nexus_document_uploads_total",
    "Всего загруженных документов",
    ["status"],  # ready, failed
)

rag_searches_total = Counter(
    "nexus_rag_searches_total",
    "Всего RAG-поисков",
    ["has_results"],  # true, false
)


# ─── Middleware ───────────────────────────────────────────
class PrometheusMiddleware(BaseHTTPMiddleware):
    """Собирает метрики по каждому HTTP-запросу."""

    async def dispatch(self, request: Request, call_next):
        path = request.url.path

        # Пропускаем сам /metrics и служебные
        if path in ("/metrics", "/health", "/version"):
            return await call_next(request)

        method = request.method
        http_requests_in_progress.labels(method=method).inc()
        start = time.time()

        try:
            response = await call_next(request)
            status = response.status_code
        except Exception:
            status = 500
            raise
        finally:
            duration = time.time() - start
            http_requests_in_progress.labels(method=method).dec()

            # Нормализуем endpoint: убираем UUID из пути
            endpoint = self._normalize_path(path)

            http_requests_total.labels(
                method=method, endpoint=endpoint, status=status
            ).inc()

            http_request_duration.labels(
                method=method, endpoint=endpoint
            ).observe(duration)

        return response

    @staticmethod
    def _normalize_path(path: str) -> str:
        """Заменяет UUID на {id} для группировки."""
        import re
        uuid_pattern = re.compile(
            r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
            re.IGNORECASE,
        )
        return uuid_pattern.sub("{id}", path)


def metrics_endpoint() -> Response:
    """Возвращает метрики в формате Prometheus."""
    return Response(
        content=generate_latest(),
        media_type=CONTENT_TYPE_LATEST,
    )
