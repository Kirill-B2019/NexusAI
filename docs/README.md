# NEXUS AI — Документация

Индекс документации проекта.

## Документы

- [ARCHITECTURE.md](ARCHITECTURE.md) — архитектура, C4, потоки данных
- [RAG.md](RAG.md) — работа с документами, чанкинг, эмбеддинги
- API.md — справочник эндпоинтов (будет на Этапе 5)
- EXPERTS.md — эксперты и промпты (будет на Этапе 5)
- DEPLOYMENT.md — развёртывание (Этап 9)
- OPERATIONS.md — бэкапы, восстановление (Этап 7)
- MONITORING.md — метрики, алерты (Этап 10)
- INTEGRATIONS.md — подключение Laravel/Nest.js (Этап 7)
- UI.md — панель управления (Этап 7)

## Обзор

NEXUS AI — внутренняя AI-платформа с 6 экспертами и оркестратором.
API-first, развёрнута на одном сервере, готова к разделению на 2 сервера.

## Стек

- Модель: Qwen3-4B Q4_K_M (llama.cpp, CPU)
- API: FastAPI + Uvicorn
- БД: PostgreSQL 17
- Векторы: Qdrant (коллекция project_documents, 384 dims, Cosine)
- Эмбеддинги: intfloat/multilingual-e5-small
- Прокси: Nginx

## Аутентификация

- Admin API-ключ — в `.env`, полный доступ
- Project API-ключ — создаётся через API, привязан к проекту
- Заголовки: `X-API-Key: <key>` или `Authorization: Bearer <key>`

---

| KB @CerberRus00 - Nexus Invest Team
