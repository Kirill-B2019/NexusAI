# NEXUS AI — Архитектура

## Обзор

NEXUS AI — внутренняя AI-платформа с 6 экспертами и оркестратором.
API-first: фронт (Laravel + Nest.js) и сторонние системы подключаются по HTTP.

## C4: Контекст

    ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
    │  Laravel UI  │     │  Nest.js UI  │     │  3rd party   │
    └──────┬───────┘     └──────┬───────┘     └──────┬───────┘
           │                    │                    │
           └────────────────────┼────────────────────┘
                                │ HTTP + X-API-Key
                                ▼
                      ┌─────────────────────┐
                      │     NEXUS AI        │
                      │   API Gateway       │
                      └─────────────────────┘

## Контейнеры (текущее состояние: 1 сервер)

    ┌───────────────────────────────────────────────────────────┐
    │ Сервер 31.128.38.96 — 6 ядер, 12 ГБ RAM, 150 ГБ NVMe      │
    │                                                           │
    │  ┌─────────┐                                             │
    │  │ Nginx   │ :80  публичный вход                         │
    │  └────┬────┘                                             │
    │       ▼                                                   │
    │  ┌─────────────┐   ┌──────────────┐                      │
    │  │ FastAPI     │──▶│ llama.cpp    │ Qwen3-4B Q4_K_M     │
    │  │ nexus-api   │   │ nexus-model  │ 2 слота, CPU        │
    │  └──┬───┬───┬──┘   └──────────────┘                      │
    │     │   │   │                                             │
    │     │   │   └──▶┌─────────────┐                          │
    │     │   │       │ embeddings  │ multilingual-e5-small    │
    │     │   │       └─────────────┘                          │
    │     │   │                                                 │
    │     │   └──▶┌──────────────┐                             │
    │     │       │ Qdrant       │ коллекция project_documents │
    │     │       └──────────────┘                             │
    │     │                                                     │
    │     └──▶┌──────────────┐                                 │
    │         │ PostgreSQL 17│ 11 таблиц + аудит               │
    │         └──────────────┘                                 │
    └───────────────────────────────────────────────────────────┘

## Будущее: 2 сервера

    ┌─────────────────────────┐     ┌─────────────────────────┐
    │ Сервер A — Platform     │     │ Сервер B — Models       │
    │                         │     │                         │
    │ Nginx → API             │────▶│ llama.cpp (Qwen3-4B)    │
    │  ├─ PostgreSQL          │HTTP │ llama.cpp (LoRA)        │
    │  ├─ Qdrant              │     │                         │
    │  ├─ Embeddings          │     │ GPU-ready (опционально) │
    │  └─ Orchestrator        │     │                         │
    └─────────────────────────┘     └─────────────────────────┘

Переход: смена MODEL_SERVER_URL в .env + firewall + VPN.

## Поток запроса (chat + RAG)

    Клиент
      │ POST /api/v1/chat { message, project_id, use_rag }
      ▼
    Nginx ──▶ FastAPI
                 │
                 ├─▶ 1. Auth: проверка API-ключа
                 │
                 ├─▶ 2. RAG:
                 │       ├─▶ embed_query(message)
                 │       ├─▶ qdrant.search(project_id, vec)
                 │       └─▶ build_context(results)
                 │
                 ├─▶ 3. Orchestrator:
                 │       ├─▶ route(message) → эксперты
                 │       ├─▶ execute(experts, prompts, context)
                 │       └─▶ aggregate(results)
                 │
                 ├─▶ 4. Сохранение: audit_log + messages
                 │
                 └─▶ 5. Ответ: { content, sources, mode }

## Компоненты

| Компонент        | Технология              | Назначение                |
|------------------|-------------------------|---------------------------|
| nexus-nginx      | Nginx alpine            | Reverse proxy, SSE        |
| nexus-api        | FastAPI + Uvicorn       | HTTP API, оркестратор     |
| nexus-model      | llama.cpp server        | Инференс Qwen3-4B         |
| nexus-embeddings | sentence-transformers   | Эмбеддинги e5-small       |
| nexus-qdrant     | Qdrant                  | Векторный поиск           |
| nexus-postgres   | PostgreSQL 17           | Метаданные, аудит         |

## Ресурсы (RAM)

| Сервис       | Лимит   | Фактически |
|--------------|---------|-----------|
| model-server | 5 ГБ    | ~2.5 ГБ   |
| postgres     | 3 ГБ    | ~500 МБ   |
| qdrant       | 2 ГБ    | ~200 МБ   |
| embeddings   | 1 ГБ    | ~300 МБ   |
| api          | 1 ГБ    | ~150 МБ   |
| nginx        | 128 МБ  | ~10 МБ    |

Запас по RAM — есть.

## Изоляция проектов

- API-ключ привязан к project_id
- RAG фильтрует по project_id на уровне Qdrant
- Документы: /app/data/documents/{project_id}/
- Диалоги, решения, задачи: FK на project_id
- allowed_experts у ключа ограничивает экспертов

Проверки:
- require_project_access(ctx, project_id) на каждом эндпоинте
- Qdrant-фильтр must: [project_id = X]
- Файлы в каталоге {project_id}/

## Технологические решения (ADR)

### ADR-001: Qwen3-4B Q4_K_M
- Контекст: 12 ГБ RAM, CPU-only
- Решение: Q4_K_M квантование (2.4 ГБ)
- Альтернативы: 1.7B (быстрее, слабее), 8B (не влезет)
- Компромисс: ~6 ток/сек — приемлемо

### ADR-002: llama.cpp вместо vLLM
- Решение: llama.cpp с --parallel 2
- Альтернативы: vLLM (GPU-only), Ollama (обёртка)
- Компромисс: нет continuous batching, но работает на CPU

### ADR-003: Qdrant вместо pgvector
- Решение: Qdrant, режим low-memory
- Альтернативы: pgvector (проще), FAISS (in-memory)
- Компромисс: +1 контейнер, лучше по скорости и фильтрам

### ADR-004: multilingual-e5-small
- Решение: intfloat/multilingual-e5-small
- Альтернативы: bge-small-en, GigaEmbeddings (2 ГБ)
- Компромисс: компактна, работает с русским

### ADR-005: API-first без встроенного UI
- Решение: только API + отладочный UI на Nginx
- Компромисс: +1 слой, но гибкость для любых клиентов

### ADR-006: API-ключи вместо JWT
- Решение: admin-ключ (.env) + project-ключи (БД)
- Компромисс: нет ролей и сессий, но проще для интеграции

## Безопасность

- Порты: наружу только 22, 80, 443 (UFW)
- Секреты: .env (chmod 600), не в Git
- Пароли БД: random 32 chars
- API-ключи: SHA-256 хеш в БД, plaintext один раз
- Аудит: все критические действия в audit_log
- Изоляция: проверки + Qdrant-фильтр
- Файлы: лимит 50 МБ

## Версии

См. VERSIONS.md в корне.### ADR-004: multilingual-e5-small
- Решение: intfloat/multilingual-e5-small
- Альтернативы: bge-small-en, GigaEmbeddings (2 ГБ)
- Компромисс: компактна, работает с русским

### ADR-005: API-first без встроенного UI
- Решение: только API + отладочный UI на Nginx
- Компромисс: +1 слой, но гибкость для любых клиентов

### ADR-006: API-ключи вместо JWT
- Решение: admin-ключ (.env) + project-ключи (БД)
- Компромисс: нет ролей и сессий, но проще для интеграции

## Безопасность

- Порты: наружу только 22, 80, 443 (UFW)
- Секреты: .env (chmod 600), не в Git
- Пароли БД: random 32 chars
- API-ключи: SHA-256 хеш в БД, plaintext один раз
- Аудит: все критические действия в audit_log
- Изоляция: проверки + Qdrant-фильтр
- Файлы: лимит 50 МБ

## Версии

См. VERSIONS.md в корне.

---

| KB @CerberRus00 - Nexus Invest Team
