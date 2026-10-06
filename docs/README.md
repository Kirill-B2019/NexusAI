# NEXUS AI — Документация

Индекс документации проекта.

## Быстрый старт

- [QUICKSTART.md](QUICKSTART.md) — установка за 30 минут
- [ARCHITECTURE.md](ARCHITECTURE.md) — обзор архитектуры, C4, потоки данных
- [INTEGRATIONS.md](INTEGRATIONS.md) — подключение Laravel, Nest.js, Python, PHP, Go

## Справочники

- [API_REFERENCE.md](API_REFERENCE.md) — полный справочник API (18 разделов)
- [API.md](API.md) — краткий справочник (для быстрого ознакомления)
- [DATA_MODEL.md](DATA_MODEL.md) — модель данных PostgreSQL
- [EXPERTS.md](EXPERTS.md) — 6 экспертов, добавление новых
- [RAG.md](RAG.md) — работа с документами, чанкинг, эмбеддинги
- [SSE.md](SSE.md) — Server-Sent Events

## Руководства

- [UI.md](UI.md) — карта экранов, компоненты для Nest.js
- [OPERATIONS.md](OPERATIONS.md) — регламент эксплуатации
- [MONITORING.md](MONITORING.md) — Prometheus, Grafana
- [TESTING.md](TESTING.md) — smoke, regression, pytest
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) — частые проблемы

## Метадокументы

- [CHANGELOG.md](CHANGELOG.md) — история изменений
- [decision-log.md](decision-log.md) — ADR (13 решений)
- [STAGES.md](STAGES.md) — трекер этапов разработки
- [HANDOFF.md](HANDOFF.md) — передача контекста

## Диаграммы

Mermaid-схемы в [diagrams/](diagrams/):

- [diagrams/README.md](diagrams/README.md) — индекс
- [diagrams/c4-containers.md](diagrams/c4-containers.md) — контейнеры
- [diagrams/sequences.md](diagrams/sequences.md) — sequence-диаграммы
- [diagrams/er-diagram.md](diagrams/er-diagram.md) — ER-диаграмма БД
- [diagrams/deployment.md](diagrams/deployment.md) — развёртывание

## Debug Admin UI

Прототип админки на Vanilla JS: http://31.128.38.96/debug/admin/index.html

- Исходники: `nginx/debug/admin/`
- 8 разделов: Dashboard, Experts, Projects, Documents, Chat, Metrics, Audit, Settings
- Фирменная тёмная тема NEXUS/HEKCYC

## Примеры кода

Готовые клиенты в [examples/](examples/):

- [examples/curl/README.md](examples/curl/README.md) — все запросы через curl
- [examples/python/client.py](examples/python/client.py) — Python-клиент
- [examples/php/NexusAiClient.php](examples/php/NexusAiClient.php) — Laravel/PHP
- [examples/typescript/](examples/typescript/) — Nest.js (service + controller + module)
- [examples/go/client.go](examples/go/client.go) — Go-клиент
- [examples/postman/nexus-ai.json](examples/postman/nexus-ai.json) — Postman-коллекция

## Обзор проекта

NEXUS AI — внутренняя AI-платформа с 6 экспертами и оркестратором.
Развёрнута на одном сервере, API-first, готова к разделению на 2 сервера.

## Стек

- **Модель:** Qwen3-4B Q4_K_M (llama.cpp, CPU, 7 threads, 2 слота, ctx 4096/слот)
- **API:** FastAPI + Uvicorn
- **БД:** PostgreSQL 17 (15 таблиц)
- **Векторы:** Qdrant (384 dim, Cosine)
- **Эмбеддинги:** intfloat/multilingual-e5-small
- **Прокси:** Nginx
- **Мониторинг:** Prometheus + Grafana

## Аутентификация

- **Admin API-ключ** — в `.env`, полный доступ
- **Project API-ключ** — создаётся через API, привязан к проекту
- **Заголовки:** `X-API-Key: <key>` или `Authorization: Bearer <key>`

---

| KB @CerberRus00 - Nexus Invest Team
