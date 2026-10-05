# NEXUS AI — API Reference

Базовый URL: `http://31.128.38.96/api`
Все защищённые эндпоинты требуют заголовок `X-API-Key: <key>` или `Authorization: Bearer <key>`.

## Аутентификация

- Admin API-ключ — в .env (полный доступ)
- Project API-ключ — создаётся через API, привязан к проекту
- Project-ключ может иметь allowed_experts — ограничение доступных экспертов

## Публичные

### GET /api/health
Healthcheck. Возвращает {"status": "ok"}.

### GET /api/version
Версия API и моделей.

## Эксперты

### GET /api/v1/experts
Список активных экспертов. Параметры:
- enabled_only=true (по умолчанию) — только is_enabled=true

### GET /api/v1/experts/all (admin)
Все эксперты, включая отключённых.

### GET /api/v1/experts/{key}
Детали эксперта.

### POST /api/v1/experts (admin)
Создать эксперта. Body:
{
  "key": "custom_expert",
  "name": "Custom Expert",
  "system_prompt": "...",
  "keywords": ["..."],
  "icon": "🎯",
  "color": "#ff0000",
  "sort_order": 100
}

### PATCH /api/v1/experts/{key} (admin)
Обновить. Изменение system_prompt сохраняет историю.

### DELETE /api/v1/experts/{key} (admin)
Soft delete. Системные (is_system=true) удалить нельзя.

### POST /api/v1/experts/{key}/enable|disable (admin)
Включить/отключить в оркестрации.

## Проекты

### GET /api/v1/projects
Список проектов. Admin видит все, project-ключ — только свой.

### POST /api/v1/projects (admin)
Создать. Body: {"name": "...", "external_id": "...", "metadata": {}}

### GET /api/v1/projects/{id}
### PATCH /api/v1/projects/{id}
### DELETE /api/v1/projects/{id} (admin)
Каскадно удаляет документы, диалоги, решения, задачи.

## API-ключи

### GET /api/v1/projects/{id}/keys
### POST /api/v1/projects/{id}/keys
Body: {"name": "...", "allowed_experts": ["fintech"], "rate_limit_per_min": 60}
Возвращает plaintext key — показывается один раз.

### DELETE /api/v1/projects/{id}/keys/{key_id}
Отозвать (is_active=false).

## Документы

### POST /api/v1/projects/{id}/documents
Multipart upload. Поле file. Лимиты: 50 МБ (файл), 100 МБ (через Nginx).
Форматы: TXT, MD, PDF, DOCX, XLSX, XLS, ODS, CSV, код.
Статус: pending → processing → ready | failed.

### GET /api/v1/projects/{id}/documents
Фильтры: ?status=ready&limit=50&offset=0

### GET /api/v1/documents/{id}
### GET /api/v1/documents/{id}/status
### POST /api/v1/documents/{id}/reindex
### DELETE /api/v1/documents/{id}
Каскадно удаляет векторы в Qdrant.

### POST /api/v1/documents/{id}/ask
Вопрос по конкретному документу.
Body:
{
  "message": "...",
  "expert": "fintech",
  "thinking": false,
  "rag_top_k": 6,
  "rag_min_score": 0.4
}

## Чат

### POST /api/v1/chat
Основной эндпоинт.

Body:
{
  "message": "обязательно",
  "expert": "system_architect",         // одиночный режим
  "experts": ["fintech", "digital_law"], // ручной список
  "thinking": false,
  "project_id": "uuid",
  "conversation_id": "uuid",
  "orchestrate": true,                   // авто-выбор, если expert/experts не заданы
  "use_llm_aggregator": false,
  "save_to_conversation": false,
  "use_rag": true,
  "rag_top_k": 4,
  "rag_min_score": 0.5,
  "document_ids": ["uuid"]               // фильтр RAG по документам
}

Ответ:
- mode: "single" | "auto_orchestration" | "manual_orchestration"
- content (single) или aggregated (orchestrated)
- sources: [{document_name, chunk_index, score, preview}]
- elapsed_s
- message_ids — если save_to_conversation=true

### POST /api/v1/chat/route
Preview роутинга без вызова LLM.
Body: {"message": "...", "expert": "...", "experts": [...]}

## Диалоги

### GET /api/v1/projects/{id}/conversations
### POST /api/v1/projects/{id}/conversations
Body: {"title": "...", "external_id": "...", "metadata": {}}

### GET /api/v1/conversations/{id}?include_messages=true
### PATCH /api/v1/conversations/{id}
### DELETE /api/v1/conversations/{id}

### GET /api/v1/conversations/{id}/messages?limit=50&before_id=<cursor>
Cursor-based пагинация. next_before_id — для следующей страницы.

### GET /api/v1/messages/{id}
### DELETE /api/v1/messages/{id}

## Решения

### GET /api/v1/projects/{id}/decisions?status=active
### POST /api/v1/projects/{id}/decisions
Body: {"title": "...", "content": "...", "status": "active", "source_message_id": "..."}
Статусы: active, superseded, archived

### GET /api/v1/decisions/{id}
### PATCH /api/v1/decisions/{id}
### DELETE /api/v1/decisions/{id}

## Задачи

### GET /api/v1/projects/{id}/tasks?status=&priority=&assignee_expert=
Сортировка: priority (urgent → high → normal → low), created_at DESC.

### POST /api/v1/projects/{id}/tasks
Body:
{
  "title": "...",
  "description": "...",
  "status": "open",
  "priority": "high",
  "assignee_expert": "software_engineer",
  "source_message_id": "...",
  "due_date": "2026-10-15",
  "external_id": "...",
  "metadata": {}
}

Статусы: open, in_progress, done, cancelled
Приоритеты: low, normal, high, urgent

### GET /api/v1/tasks/{id}
### PATCH /api/v1/tasks/{id}
### DELETE /api/v1/tasks/{id}

## Админ

### GET /api/v1/admin/stats
Общая статистика: проекты, документы, чанки, диалоги, сообщения, решения, задачи, ключи, эксперты, аудит за 24ч.

### GET /api/v1/admin/projects-usage
Разбивка по проектам.

### GET /api/v1/admin/system-health
Проверка всех 5 сервисов: api, model, embeddings, postgres, qdrant.

### POST /api/v1/admin/experts/{key}/test
Тестовый прогон промпта.
Body: {"message": "...", "thinking": false}

### GET /api/v1/admin/audit
Фильтры: actor, action (с % для wildcard), project_id, resource_type, from_time, to_time.

### GET /api/v1/admin/audit/actions
Список типов действий с количеством.

### GET /api/v1/admin/audit/actors
Список actors с количеством и last_seen.

## Формат ошибок

{
  "detail": "текст ошибки"
}

Коды:
- 400 — неверный запрос
- 401 — нет/невалидный API-ключ
- 403 — нет доступа (или admin-only)
- 404 — ресурс не найден
- 409 — конфликт
- 413 — файл слишком большой
- 502 — ошибка модели
- 504 — таймаут модели

## CORS

Разрешённые origins задаются в .env: CORS_ORIGINS=http://localhost:3000,...

Применяется:
- allow_methods: GET, POST, PATCH, DELETE, OPTIONS
- allow_headers: X-API-Key, Authorization, Content-Type, X-Request-ID, Idempotency-Key
- expose_headers: X-Request-ID, X-Process-Time-Ms, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
- max_age: 3600

Запросы с других origins блокируются на уровне preflight.

## Rate Limiting

In-memory счётчик по API-ключу (sliding window 60 сек).

| Тип ключа   | Лимит      |
|-------------|-----------|
| admin       | 600 / мин |
| project     | 60 / мин  |

Заголовки в каждом ответе:
- X-RateLimit-Limit
- X-RateLimit-Remaining
- X-RateLimit-Reset (только при 429)
- Retry-After (только при 429)

При превышении — HTTP 429 с телом {"detail": "Rate limit exceeded..."}.

Исключения (не считаются): /health, /version, /docs, /redoc, /openapi.json.

## SSE-стрим

Полное описание — docs/SSE.md.

Основное:
- Эндпоинт: POST /api/v1/chat/stream
- Формат: text/event-stream
- События: start, expert_start, reasoning, token, expert_done, done, error
- Клиенты: fetch + ReadableStream (JS), EventSource (только GET — не подходит для POST)

## OpenAPI / Swagger

Документация доступна по адресу:
- /api/docs — Swagger UI
- /api/redoc — ReDoc
- /api/openapi.json — схема

Требуется admin-ключ в X-API-Key.
