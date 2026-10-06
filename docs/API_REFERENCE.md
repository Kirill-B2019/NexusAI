# NEXUS AI — Полный API Reference

**Версия:** 1.0
**Обновлено:** 2026-10-06
**Base URL:** `http://31.128.38.96/api`

---

## Содержание

1. [Обзор](#1-обзор)
2. [Аутентификация](#2-аутентификация)
3. [Формат ошибок](#3-формат-ошибок)
4. [Rate limiting](#4-rate-limiting)
5. [System endpoints](#5-system-endpoints)
6. [Experts](#6-experts)
7. [Projects](#7-projects)
8. [API Keys](#8-api-keys)
9. [Documents](#9-documents)
10. [Conversations](#10-conversations)
11. [Messages](#11-messages)
12. [Decisions](#12-decisions)
13. [Tasks](#13-tasks)
14. [Chat](#14-chat)
15. [Route preview](#15-route-preview)
16. [Admin](#16-admin)
17. [Audit](#17-audit)
18. [Metrics](#18-metrics)

---

## 1. Обзор

NEXUS AI — внутренняя AI-платформа с 6 экспертами и оркестратором.
Все эндпоинты — REST + JSON. SSE-стрим — `text/event-stream`.

**Базовый URL:** `http://31.128.38.96/api`

**Префикс API:** `/v1/*` для бизнес-эндпоинтов, кроме `/health`, `/version`, `/docs`, `/openapi.json`, `/metrics`.

---

## 2. Аутентификация

**Два типа ключей:**

| Тип | Где получить | Права |
|-----|-------------|-------|
| **Admin** | В .env на сервере | Полный доступ ко всему |
| **Project** | `POST /v1/projects/{id}/keys` | Только свой проект |

**Передача ключа:**

    X-API-Key: <key>

или

    Authorization: Bearer <key>

**Admin-ключ:** статичный, задаётся при развёртывании.
**Project-ключи:** SHA-256 хеш в БД, plaintext показывается один раз.

**Project-ключ может быть ограничен:**
- `allowed_experts: ["fintech", "digital_law"]` — доступ только к этим экспертам
- `rate_limit_per_min: 120` — индивидуальный лимит
- `expires_at: "2027-01-01T00:00:00Z"` — срок действия

---

## 3. Формат ошибок

Все ошибки — JSON с полем `detail`:

    {
      "detail": "Описание ошибки"
    }

**HTTP-коды:**

| Код | Значение |
|-----|----------|
| 200 | OK |
| 201 | Created |
| 204 | No Content (удаление) |
| 400 | Bad Request — валидация |
| 401 | Unauthorized — нет/невалидный ключ |
| 403 | Forbidden — нет доступа |
| 404 | Not Found |
| 409 | Conflict — дубликат |
| 413 | Payload Too Large — файл > 50 МБ |
| 429 | Too Many Requests — rate limit |
| 500 | Internal Server Error |
| 502 | Bad Gateway — ошибка модели |
| 504 | Gateway Timeout — модель не ответила |

---

## 4. Rate limiting

| Тип ключа | Лимит по умолчанию |
|-----------|-------------------|
| Admin | 600 req/min |
| Project | 60 req/min |

**Заголовки в ответе:**

    X-RateLimit-Limit: 60
    X-RateLimit-Remaining: 45

**При превышении:**

    HTTP 429
    X-RateLimit-Limit: 60
    X-RateLimit-Remaining: 0
    X-RateLimit-Reset: 15
    Retry-After: 15
    {"detail": "Rate limit exceeded. Try again later."}

**Исключения (не считаются):** `/health`, `/version`, `/docs`, `/redoc`, `/openapi.json`, `/metrics`.

---

## 5. System endpoints

### GET /health

Публичный. Проверка живости API.

**Авторизация:** нет

**Ответ 200:**

    {"status": "ok"}

### GET /version

Публичный. Версия API и моделей.

**Авторизация:** нет

**Ответ 200:**

    {
      "api_version": "1.0.0",
      "service": "nexus-ai",
      "models": {
        "chat": "qwen3-4b",
        "embeddings": "intfloat/multilingual-e5-small"
      }
    }

---

## 6. Experts

### GET /v1/experts

Список **активных** экспертов (только `is_enabled=true`).

**Авторизация:** admin или project

**Query-параметры:**
- `enabled_only` (bool, default: true) — если false, требует admin, показывает всех

**Ответ 200:**

    {
      "experts": [
        {
          "key": "system_architect",
          "name": "Системный архитектор",
          "description": "Архитектура ПО, компоненты, API, компромиссы",
          "icon": "🏗",
          "color": "#2563eb",
          "sort_order": 10
        }
      ]
    }

**Фильтрация:** если у ключа задан `allowed_experts`, возвращаются только разрешённые эксперты.

### GET /v1/experts/all

Все эксперты, включая отключённых.

**Авторизация:** admin

**Ответ 200:** массив с полями: key, name, description, system_prompt, keywords, icon, color, sort_order, is_enabled, is_system, metadata.

### GET /v1/experts/{key}

Детали эксперта.

**Авторизация:** admin или project

**Ответ 200 (admin):** полный объект, включая system_prompt.
**Ответ 200 (project):** без system_prompt.
**Ответ 404:** `{"detail": "Expert 'unknown' not found"}`

### POST /v1/experts

Создать нового эксперта.

**Авторизация:** admin

**Тело запроса:**

    {
      "key": "cybersec_expert",
      "name": "Эксперт по кибербезопасности",
      "description": "Безопасность приложений",
      "system_prompt": "Ты — CYBERSEC_EXPERT...",
      "keywords": ["безопасность", "OWASP", "penetration test"],
      "icon": "🔒",
      "color": "#1f2937",
      "sort_order": 70,
      "is_enabled": true,
      "metadata": {}
    }

**Правила валидации:**
- `key`: `^[a-z][a-z0-9_]*$`, 2–64 символа
- `name`: 2–200 символов
- `system_prompt`: минимум 20 символов
- `keywords`: массив строк

**Ответ 201:** полный объект с `is_system: false`.
**Ответ 409:** если `key` уже существует.

### PATCH /v1/experts/{key}

Обновить эксперта.

**Авторизация:** admin

**Тело запроса (все поля опциональны):**

    {
      "name": "Новое имя",
      "system_prompt": "Обновлённый промпт",
      "keywords": ["новые", "ключевые"],
      "is_enabled": false
    }

**Особенность:** при изменении `system_prompt` — старая версия сохраняется в `expert_prompt_history`.

**Ответ 200:** обновлённый объект.

### DELETE /v1/experts/{key}

Мягкое удаление (soft delete через `deleted_at`).

**Авторизация:** admin

**Ограничение:** нельзя удалить `is_system=true` (→ 403).

**Ответ 204:** без тела.

### POST /v1/experts/{key}/enable

Включить эксперта в оркестрацию.

**Авторизация:** admin

**Ответ 200:**

    {"key": "digital_law", "is_enabled": true}

### POST /v1/experts/{key}/disable

Отключить эксперта.

**Ответ 200:**

    {"key": "digital_law", "is_enabled": false}

**Отключённый эксперт:**
- Не вызывается в auto-режиме
- Возвращает `experts_skipped: [{"key": "...", "reason": "disabled"}]` в ручном режиме
- Возвращает 404 при явном вызове `/v1/chat` с `expert=...`

---

## 7. Projects

### GET /v1/projects

Список проектов.

**Авторизация:** admin (видит все) или project (только свой)

**Ответ 200:**

    {
      "projects": [
        {
          "id": "uuid",
          "external_id": "laravel-proj-001",
          "name": "Первый проект",
          "description": "...",
          "created_by_system": "admin",
          "metadata": {},
          "created_at": "2026-10-05T10:00:00+00:00",
          "updated_at": "2026-10-05T10:00:00+00:00"
        }
      ]
    }

### POST /v1/projects

Создать проект.

**Авторизация:** admin

**Тело:**

    {
      "name": "Новый проект",
      "description": "Описание",
      "external_id": "laravel-proj-002",
      "metadata": {"owner": "test"}
    }

**Ответ 201:** объект проекта.
**Ответ 409:** если `external_id` уже существует.

### GET /v1/projects/{id}

**Ответ 404:** если не найден или нет доступа.

### PATCH /v1/projects/{id}

Обновить.

**Авторизация:** admin или владелец (project-ключ того же проекта)

### DELETE /v1/projects/{id}

Удалить каскадно (документы, диалоги, решения, задачи).

**Авторизация:** admin

**Ответ 204.**

---

## 8. API Keys

### GET /v1/projects/{id}/keys

Список ключей проекта.

**Авторизация:** admin или владелец проекта

**Ответ 200:**

    {
      "keys": [
        {
          "id": "uuid",
          "name": "Laravel production",
          "key_prefix": "nx_a1cEKT3l-",
          "type": "project",
          "is_active": true,
          "allowed_experts": ["system_architect", "fintech"],
          "rate_limit_per_min": 120,
          "created_at": "...",
          "last_used_at": "...",
          "expires_at": null
        }
      ]
    }

**Важно:** поле `key_hash` (SHA-256) в ответе **не возвращается**. Только `key_prefix` для идентификации.

### POST /v1/projects/{id}/keys

Создать новый ключ.

**Авторизация:** admin или владелец проекта

**Тело:**

    {
      "name": "Laravel production",
      "allowed_experts": ["system_architect", "fintech"],
      "rate_limit_per_min": 120,
      "expires_at": "2027-01-01T00:00:00Z"
    }

Все поля кроме `name` — опциональны.

**Ответ 201:**

    {
      "id": "uuid",
      "name": "Laravel production",
      "key_prefix": "nx_a1cEKT3l-",
      "key": "nx_a1cEKT3l-xxxxxxxxxxxxxxxxxxxxxxxxxxx",
      "warning": "Сохраните ключ — он больше не будет показан",
      "allowed_experts": [...],
      "rate_limit_per_min": 120,
      "is_active": true,
      "created_at": "..."
    }

**⚠️ Поле `key` показывается ОДИН РАЗ.** Сохраните его на стороне клиента.

### DELETE /v1/projects/{id}/keys/{key_id}

Отозвать ключ (soft delete: `is_active=false`).

**Авторизация:** admin или владелец проекта

**Ответ 204.**

**После отзыва:** запросы с этим ключом возвращают 401 `{"detail": "API key disabled"}`.

---

*Продолжение в Части 2: Documents, Conversations, Messages.*

---

| KB @CerberRus00 - Nexus Invest Team

---

## 9. Documents

### POST /v1/projects/{id}/documents

Загрузить документ.

**Авторизация:** admin или владелец проекта

**Content-Type:** `multipart/form-data`

**Поле:** `file` — единственное обязательное.

**Лимиты:**
- Максимальный размер: 50 МБ (файл), 100 МБ (через Nginx)
- Поддерживаемые форматы: .txt, .md, .markdown, .log, .pdf, .docx, .doc, .xlsx, .xlsm, .xls, .ods, .csv, .py, .js, .ts, .json, .yaml, .yml, .sh, .sql, .html, .css, .go, .rs, .java, .c, .cpp, .h, .xml

**Пример:**

    curl -X POST http://31.128.38.96/api/v1/projects/$PROJECT_ID/documents \
      -H "X-API-Key: $ADMIN_KEY" \
      -F "file=@/path/to/document.pdf"

**Ответ 201:**

    {
      "id": "uuid",
      "project_id": "uuid",
      "filename": "document.pdf",
      "original_filename": "document.pdf",
      "file_size": 432675,
      "mime_type": "application/pdf",
      "status": "pending",
      "chunks_count": 0,
      "error": null,
      "created_at": "2026-10-05T10:00:00+00:00",
      "processed_at": null
    }

**Жизненный цикл:**
1. `pending` — файл загружен, обработка в очереди
2. `processing` — идёт извлечение, чанкинг, эмбеддинги
3. `ready` — документ доступен для RAG
4. `failed` — ошибка (см. поле `error`)

**Фоновая обработка:** занимает 10–60 секунд на документ 400 КБ.

**Ответ 413:** `{"detail": "Файл больше 50 МБ"}`
**Ответ 400:** `{"detail": "Неподдерживаемый формат '.exe'..."}`

### GET /v1/projects/{id}/documents

Список документов проекта.

**Query-параметры:**
- `status` — фильтр по статусу (`pending`, `processing`, `ready`, `failed`)
- `limit` (1–200, default 50)
- `offset` (default 0)

**Ответ 200:**

    {
      "documents": [...],
      "total": 12,
      "limit": 50,
      "offset": 0
    }

### GET /v1/documents/{doc_id}

Метаданные документа.

### GET /v1/documents/{doc_id}/status

Краткий статус обработки.

**Ответ 200:**

    {
      "id": "uuid",
      "status": "ready",
      "chunks_count": 17,
      "error": null,
      "processed_at": "2026-10-05T10:01:00+00:00"
    }

**Использование:** polling каждые 3 секунды для отслеживания обработки.

### POST /v1/documents/{doc_id}/reindex

Переиндексировать документ.

**Что делает:**
1. Удаляет старые точки из Qdrant
2. Удаляет старые чанки из БД
3. Сбрасывает статус на `pending`
4. Запускает повторную обработку

**Ответ 200:**

    {
      "id": "uuid",
      "status": "pending",
      "message": "Переиндексация запущена"
    }

**Когда использовать:** после обновления extractors, chunking, embeddings.

### DELETE /v1/documents/{doc_id}

Удалить документ.

**Что удаляется:**
1. Точки из Qdrant (`delete_by_document`)
2. Файл с диска
3. Запись из БД (каскадно `document_chunks`)

**Ответ 204.**

### POST /v1/documents/{doc_id}/ask

Задать вопрос по конкретному документу.

**Авторизация:** admin или владелец проекта

**Тело:**

    {
      "message": "О чём этот документ?",
      "expert": "fintech",
      "thinking": false,
      "rag_top_k": 6,
      "rag_min_score": 0.4
    }

**Особенность:** RAG ищет **только в этом документе** (`document_ids: [doc_id]`).

**Ответ 200:**

    {
      "document_id": "uuid",
      "document_name": "report.pdf",
      "expert": "fintech",
      "rag_used": true,
      "sources": [
        {
          "index": 1,
          "document_id": "uuid",
          "document_name": "report.pdf",
          "chunk_index": 3,
          "score": 0.812,
          "preview": "Текст фрагмента..."
        }
      ],
      "content": "Ответ эксперта...",
      "reasoning": "",
      "timings": {"predicted_per_second": 4.8}
    }

**Если ничего не найдено:**

    {
      "document_id": "uuid",
      "document_name": "report.pdf",
      "rag_used": false,
      "message": "Не найдено релевантных фрагментов в документе",
      "sources": [],
      "content": null
    }

**Ответ 409:** если документ ещё не готов (`status != "ready"`).

---

## 10. Conversations

### GET /v1/projects/{id}/conversations

Список диалогов проекта.

**Query:**
- `limit` (1–200, default 50)
- `offset` (default 0)

**Сортировка:** `updated_at DESC` (свежие сверху).

**Ответ 200:**

    {
      "conversations": [
        {
          "id": "uuid",
          "project_id": "uuid",
          "external_id": null,
          "title": "Что такое API Gateway? Кратко.",
          "metadata": {},
          "created_at": "...",
          "updated_at": "..."
        }
      ],
      "total": 5,
      "limit": 50,
      "offset": 0
    }

### POST /v1/projects/{id}/conversations

Создать диалог.

**Тело:**

    {
      "title": "Обсуждение архитектуры",
      "external_id": "laravel-conv-001",
      "metadata": {"source": "manual"}
    }

**Все поля опциональны.** Если `title` не задан — автозаполнение из первого user-сообщения (первые 60 символов).

**Ответ 201.**

### GET /v1/conversations/{conv_id}

Получить диалог.

**Query:**
- `include_messages` (bool, default false) — если true, возвращает историю сообщений

**Ответ 200:**

    {
      "id": "uuid",
      "project_id": "uuid",
      "title": "...",
      "created_at": "...",
      "updated_at": "...",
      "messages": [...]
    }

### PATCH /v1/conversations/{conv_id}

Переименовать или обновить метаданные.

**Тело:**

    {
      "title": "Новое название",
      "metadata": {...}
    }

### DELETE /v1/conversations/{conv_id}

Удалить диалог каскадно (сообщения тоже).

**Ответ 204.**

---

## 11. Messages

### GET /v1/conversations/{conv_id}/messages

История сообщений с **cursor-based пагинацией**.

**Query:**
- `limit` (1–200, default 50)
- `before_id` (cursor) — вернуть сообщения ДО указанного

**Особенность:** сообщения возвращаются в **хронологическом** порядке (от старых к новым).

**Ответ 200:**

    {
      "messages": [
        {
          "id": "uuid",
          "conversation_id": "uuid",
          "role": "user",
          "expert": null,
          "experts_used": null,
          "content": "Что такое API Gateway?",
          "reasoning": null,
          "mode": null,
          "metadata": {"expert": "system_architect", "thinking": false, "rag_used": false},
          "created_at": "..."
        },
        {
          "id": "uuid",
          "conversation_id": "uuid",
          "role": "assistant",
          "expert": "system_architect",
          "experts_used": null,
          "content": "API Gateway — это...",
          "reasoning": "",
          "mode": "single",
          "metadata": {"sources": []},
          "created_at": "..."
        }
      ],
      "next_before_id": "uuid",
      "count": 2
    }

**Пагинация по страницам:**

    # Первая страница (последние 50)
    GET /v1/conversations/{id}/messages?limit=50

    # Следующая страница (следующие 50 назад)
    GET /v1/conversations/{id}/messages?limit=50&before_id=<next_before_id>

**Роли:** `user`, `assistant`, `system`

### GET /v1/messages/{msg_id}

Получить конкретное сообщение.

### DELETE /v1/messages/{msg_id}

Удалить сообщение.

**Ответ 204.**

---

*Продолжение в Части 3: Decisions, Tasks, Chat, Route.*

---

| KB @CerberRus00 - Nexus Invest Team

---

## 12. Decisions

«Решения» — важные выводы, которые нужно сохранить из диалога.

### GET /v1/projects/{id}/decisions

Список решений проекта.

**Query:**
- `status` — фильтр (`active`, `superseded`, `archived`)
- `limit`, `offset`

**Ответ 200:**

    {
      "decisions": [
        {
          "id": "uuid",
          "project_id": "uuid",
          "external_id": "adr-001",
          "title": "Использовать модульный монолит",
          "content": "Для проекта выбрана...",
          "source_message_id": "uuid",
          "status": "active",
          "metadata": {},
          "created_at": "...",
          "updated_at": "..."
        }
      ],
      "total": 5,
      "limit": 50,
      "offset": 0
    }

**Важно:** поле `total` учитывает фильтр по `status`.

### POST /v1/projects/{id}/decisions

Создать решение.

**Тело:**

    {
      "title": "Использовать модульный монолит",
      "content": "Полный текст решения...",
      "status": "active",
      "source_message_id": "uuid",
      "external_id": "adr-001",
      "metadata": {"source": "architecture-review"}
    }

**Валидация:**
- `title`: 2–300 символов
- `content`: минимум 1 символ
- `status`: один из `active`, `superseded`, `archived`
- `source_message_id`: если задан — должен существовать в БД

**Ответ 201.**
**Ответ 400:** `{"detail": "source_message_id not found"}`

### GET /v1/decisions/{decision_id}

### PATCH /v1/decisions/{decision_id}

Обновить.

**Тело (опционально):**

    {
      "status": "superseded",
      "content": "Обновлённое содержимое"
    }

**Ошибка 400:** невалидный статус.

### DELETE /v1/decisions/{decision_id}

Удалить (hard delete).

**Ответ 204.**

---

## 13. Tasks

«Задачи» — то, что нужно сделать в проекте.

### GET /v1/projects/{id}/tasks

Список задач.

**Query:**
- `status` — `open`, `in_progress`, `done`, `cancelled`
- `priority` — `low`, `normal`, `high`, `urgent`
- `assignee_expert` — ключ эксперта
- `limit`, `offset`

**Сортировка:** `priority` (urgent → high → normal → low), затем `created_at DESC`.

**Ответ 200:**

    {
      "tasks": [
        {
          "id": "uuid",
          "project_id": "uuid",
          "external_id": "task-001",
          "title": "Написать API Gateway",
          "description": "...",
          "status": "open",
          "priority": "high",
          "assignee_expert": "software_engineer",
          "source_message_id": null,
          "due_date": "2026-10-15",
          "metadata": {"sprint": 1},
          "created_at": "...",
          "updated_at": "..."
        }
      ],
      "total": 1,
      "limit": 50,
      "offset": 0
    }

**Поле `total` учитывает все фильтры.**

### POST /v1/projects/{id}/tasks

Создать задачу.

**Тело:**

    {
      "title": "Написать API Gateway",
      "description": "Реализовать точку входа",
      "status": "open",
      "priority": "high",
      "assignee_expert": "software_engineer",
      "source_message_id": "uuid",
      "due_date": "2026-10-15",
      "external_id": "task-001",
      "metadata": {"sprint": 1}
    }

**Валидация:**
- `title`: 2–300 символов
- `status`: `open`, `in_progress`, `done`, `cancelled`
- `priority`: `low`, `normal`, `high`, `urgent`
- `due_date`: формат `YYYY-MM-DD`
- `source_message_id`: должен существовать

**Ответ 201.**

**Ошибки 400:**
- `{"detail": "Invalid status. Allowed: ..."}`
- `{"detail": "Invalid priority. Allowed: ..."}`
- `{"detail": "due_date must be YYYY-MM-DD"}`

### GET /v1/tasks/{task_id}

### PATCH /v1/tasks/{task_id}

Обновить.

**Тело (опционально):**

    {
      "status": "in_progress",
      "priority": "urgent",
      "due_date": "2026-10-20"
    }

Для сброса `due_date`: `"due_date": null`.

### DELETE /v1/tasks/{task_id}

**Ответ 204.**

---

## 14. Chat

### POST /v1/chat

**Главный эндпоинт.** Работает в 3 режимах.

**Авторизация:** admin или project

**Тело:**

    {
      "message": "обязательно",
      "expert": "system_architect",
      "experts": ["fintech", "digital_law"],
      "thinking": false,
      "project_id": "uuid",
      "conversation_id": "uuid",
      "orchestrate": true,
      "use_llm_aggregator": false,
      "save_to_conversation": false,
      "use_rag": true,
      "rag_top_k": 4,
      "rag_min_score": 0.5,
      "document_ids": ["uuid"]
    }

**Режимы работы:**

**Режим 1 — single (один эксперт):**
- Условие: `expert` задан И `orchestrate=false` И `experts` пуст
- Результат: `mode: "single"`

**Режим 2 — auto_orchestration:**
- Условие: `orchestrate=true`, `expert` и `experts` не заданы
- Роутер сам выбирает экспертов по keyword/LLM
- Результат: `mode: "auto_orchestration"`

**Режим 3 — manual_orchestration:**
- Условие: `experts` задан (список)
- Только указанные эксперты
- Результат: `mode: "manual_orchestration"`

**Приоритет:** `experts` > `expert` > auto.

**Поля:**

| Поле | Тип | Default | Описание |
|------|-----|---------|----------|
| `message` | str | — | Обязательно, 1–32000 символов |
| `expert` | str? | null | Одиночный эксперт |
| `experts` | list? | null | Список экспертов (макс. 6) |
| `thinking` | bool | false | Режим размышления |
| `project_id` | uuid? | null | Проект для RAG |
| `conversation_id` | uuid? | null | Сохранить в диалог |
| `orchestrate` | bool | true | Авто-выбор |
| `use_llm_aggregator` | bool | false | LLM-синтез (медленно) |
| `save_to_conversation` | bool | false | Сохранять в БД |
| `use_rag` | bool | true | RAG-поиск |
| `rag_top_k` | int | 4 | 1–10 |
| `rag_min_score` | float | 0.5 | 0.0–1.0 |
| `document_ids` | list? | null | Фильтр RAG по документам |

**Ответ 200 (single):**

    {
      "mode": "single",
      "expert": "system_architect",
      "content": "API Gateway — это...",
      "reasoning": "",
      "timings": {"predicted_per_second": 4.8, "predicted_n": 512},
      "elapsed_s": 88.35,
      "rag_used": true,
      "sources": [
        {"index": 1, "document_id": "uuid", "document_name": "report.pdf", "chunk_index": 3, "score": 0.812, "preview": "..."}
      ],
      "message_ids": {"user": "uuid", "assistant": "uuid"}
    }

**Ответ 200 (orchestrated):**

    {
      "mode": "auto_orchestration",
      "experts_requested": null,
      "experts_used": ["fintech", "digital_law"],
      "experts_skipped": [],
      "route_reason": "Совпадение по ключевым словам: fintech, digital_law",
      "route_method": "keyword",
      "results": [
        {"expert": "fintech", "ok": true, "content": "...", "reasoning": "", "timings": {...}},
        {"expert": "digital_law", "ok": true, "content": "...", "reasoning": "", "timings": {...}}
      ],
      "aggregated": "## Совет экспертов NEXUS AI\n\n### 💰 Финтех-эксперт\n...",
      "aggregator": "programmatic",
      "summary": {"total": 2, "ok": 2, "failed": 0},
      "elapsed_s": 245.6,
      "rag_used": true,
      "sources": [...],
      "message_ids": {"user": "uuid", "assistant": "uuid"}
    }

**Поля ответа:**

| Поле | Описание |
|------|----------|
| `mode` | `single` / `auto_orchestration` / `manual_orchestration` |
| `experts_used` | Фактически использованные |
| `experts_skipped` | Пропущенные с причиной (`disabled` / `unknown`) |
| `route_method` | `explicit` / `manual` / `keyword` / `llm` |
| `results` | Сырые ответы экспертов |
| `aggregated` | Финальный текст (для orchestrated) |
| `content` | Финальный текст (для single) |
| `aggregator` | `programmatic` / `llm` |
| `sources` | RAG-источники |
| `message_ids` | ID сообщений в БД (если сохраняли) |

**Ошибки:**

- 403: `{"detail": "Expert 'digital_law' not allowed for this key"}`
- 404: `{"detail": "Expert 'unknown' not found or disabled"}`
- 502: `{"detail": "All experts failed: fintech: timeout; ..."}`
- 504: `{"detail": "Model timeout — reduce prompt or disable thinking"}`

### POST /v1/chat/stream

**SSE-стрим.** Подробности — `docs/SSE.md`.

**Тело:** такое же, как у `/v1/chat`, но без `conversation_id`, `save_to_conversation`, `use_llm_aggregator`.

**Response:** `Content-Type: text/event-stream`

**События:**
- `start` — начало, метаданные (mode, experts, sources)
- `expert_start` — начало эксперта
- `reasoning` — токен размышления
- `token` — токен финального ответа
- `expert_done` — эксперт завершил
- `expert_error` — ошибка эксперта
- `done` — финал с `aggregated`
- `error` — фатальная ошибка

**Пример:**

    curl -N -s -X POST http://31.128.38.96/api/v1/chat/stream \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"message": "Что такое API Gateway?", "expert": "system_architect"}'

---

## 15. Route preview

### POST /v1/chat/route

Preview роутинга **без вызова LLM**. Возвращает, каких экспертов выбрал бы роутер.

**Авторизация:** admin или project

**Тело:**

    {
      "message": "Оцени проект платёжной системы",
      "expert": null,
      "experts": null
    }

**Ответ 200:**

    {
      "experts_requested": null,
      "experts_used": ["fintech", "project_scoring", "digital_law"],
      "experts_skipped": [],
      "reason": "Совпадение по ключевым словам: fintech, project_scoring, digital_law",
      "method": "keyword"
    }

**Method:** `explicit` / `manual` / `keyword` / `llm` / `chitchat` / `error`

**Использование:** UI может показать пользователю превью, кто будет отвечать, до отправки запроса.

---

*Продолжение в Части 4: Admin, Audit, Metrics.*

---

| KB @CerberRus00 - Nexus Invest Team

---

## 16. Admin

Все admin-эндпоинты требуют **admin-ключ**. Project-ключ вернёт **403**.

### GET /v1/admin/stats

Общая статистика системы.

**Авторизация:** admin

**Ответ 200:**

    {
      "projects": 2,
      "documents": {
        "total": 2,
        "ready": 2,
        "failed": 0
      },
      "chunks": 20,
      "conversations": 1,
      "messages": 2,
      "decisions": 1,
      "tasks": 1,
      "api_keys": {
        "active": 2,
        "total": 3
      },
      "experts": {
        "enabled": 6,
        "total": 6
      },
      "audit_24h": {
        "total": 47,
        "chat_requests": 13
      }
    }

### GET /v1/admin/projects-usage

Разбивка метрик по проектам.

**Авторизация:** admin

**Query:**
- `limit` (1–200, default 50)

**Ответ 200:**

    {
      "projects": [
        {
          "id": "uuid",
          "name": "Первый тестовый проект",
          "external_id": "laravel-proj-001",
          "documents_count": 2,
          "conversations_count": 0,
          "messages_count": 0,
          "decisions_count": 0,
          "tasks_count": 0,
          "active_keys": 2,
          "last_activity": "2026-10-05T09:39:01+00:00"
        }
      ]
    }

### GET /v1/admin/system-health

Проверка всех 5 сервисов.

**Авторизация:** admin

**Ответ 200:**

    {
      "status": "ok",
      "services": {
        "api": {"status": "ok"},
        "model": {"status": "ok", "code": 200},
        "embeddings": {"status": "ok", "model": "intfloat/multilingual-e5-small"},
        "postgres": {"status": "ok"},
        "qdrant": {"status": "ok", "code": 200}
      },
      "timestamp": 1791195404.918
    }

**`status` верхнего уровня:**
- `ok` — все сервисы работают
- `degraded` — хотя бы один сервис недоступен

### POST /v1/admin/experts/{key}/test

Тестовый прогон промпта эксперта (без RAG, без оркестрации).

**Авторизация:** admin

**Тело:**

    {
      "message": "Назови одну ключевую метрику для платёжной системы.",
      "thinking": false
    }

**Ответ 200:**

    {
      "expert": "fintech",
      "message": "Назови одну ключевую метрику...",
      "thinking": false,
      "content": "Среднее время обработки транзакции...",
      "reasoning": "",
      "timings": {"predicted_per_second": 4.88},
      "elapsed_s": 88.35
    }

**Использование:** для отладки промптов при редактировании.

---

## 17. Audit

### GET /v1/admin/audit

Журнал аудита с фильтрами.

**Авторизация:** admin

**Query:**
- `actor` — `admin` или `project:<uuid>`
- `action` — точное совпадение или с `%` для wildcard
- `project_id` — UUID проекта
- `resource_type` — `document`, `decision`, `task`, `conversation`, `api_key`, `expert`, `message`
- `from_time` — ISO timestamp
- `to_time` — ISO timestamp
- `limit` (1–500, default 50)
- `offset`

**Примеры:**

    # Последние 10 событий
    GET /v1/admin/audit?limit=10

    # Только chat.single
    GET /v1/admin/audit?action=chat.single

    # Все действия, начинающиеся с chat.
    GET /v1/admin/audit?action=chat.%25

    # События конкретного проекта
    GET /v1/admin/audit?project_id=<uuid>

    # События за последний час
    GET /v1/admin/audit?from_time=2026-10-06T08:00:00

**Ответ 200:**

    {
      "events": [
        {
          "id": 48,
          "actor": "admin",
          "api_key_id": null,
          "project_id": "uuid",
          "action": "task.create",
          "resource_type": "task",
          "resource_id": "uuid",
          "details": {"title": "...", "priority": "urgent"},
          "ip": "127.0.0.1",
          "user_agent": "curl/8.5.0",
          "request_id": "req_abc123",
          "created_at": "2026-10-05T10:11:06+00:00"
        }
      ],
      "total": 48,
      "limit": 50,
      "offset": 0
    }

### GET /v1/admin/audit/actions

Список всех типов actions с количеством.

**Авторизация:** admin

**Ответ 200:**

    {
      "actions": [
        {"action": "chat.single", "count": 11},
        {"action": "api_key.create", "count": 4},
        {"action": "document.ask", "count": 3},
        {"action": "expert.disable", "count": 3},
        {"action": "expert.enable", "count": 3}
      ]
    }

### GET /v1/admin/audit/actors

Список actors с количеством и последним появлением.

**Авторизация:** admin

**Ответ 200:**

    {
      "actors": [
        {
          "actor": "admin",
          "count": 48,
          "last_seen": "2026-10-05T10:20:06+00:00"
        }
      ]
    }

**Типы actors:**
- `admin` — действия с admin-ключом
- `project:<uuid>` — действия с project-ключом
- `laravel` / `nest` — если интеграция с фронтом передаёт это

---

## 18. Metrics

### GET /metrics

**Prometheus-метрики.** Формат — `text/plain` (Prometheus exposition format).

**Авторизация:** **нет** (эндпоинт открыт для scrape Prometheus).

**Метрики:**

| Метрика | Тип | Описание |
|---------|-----|----------|
| `nexus_http_requests_total{method, endpoint, status}` | Counter | Всего HTTP-запросов |
| `nexus_http_request_duration_seconds{method, endpoint}` | Histogram | Длительность запросов |
| `nexus_http_requests_in_progress{method}` | Gauge | Активные запросы |
| `nexus_chat_requests_total{mode}` | Counter | Запросы чата (single/auto/manual) |
| `nexus_chat_experts_used` | Histogram | Сколько экспертов на запрос |
| `nexus_document_uploads_total{status}` | Counter | Загрузки документов |
| `nexus_rag_searches_total{has_results}` | Counter | RAG-поиски |

**Пример запроса:**

    curl http://31.128.38.96/api/metrics | head -30

**Пример вывода:**

    # HELP nexus_http_requests_total Всего HTTP-запросов
    # TYPE nexus_http_requests_total counter
    nexus_http_requests_total{endpoint="/v1/experts",method="GET",status="200"} 33.0
    nexus_http_requests_total{endpoint="/v1/experts",method="GET",status="401"} 6.0

**Исключения:** `/metrics`, `/health`, `/version` не учитываются в собственных метриках (чтобы не засорять данными от Prometheus).

---

## Дополнительные разделы

### OpenAPI / Swagger UI

Документация в интерактивном виде:

- **Swagger UI:** `http://31.128.38.96/api/docs`
- **ReDoc:** `http://31.128.38.96/api/redoc`
- **OpenAPI JSON:** `http://31.128.38.96/api/openapi.json`

**Авторизация:** admin-ключ в `X-API-Key`.

### CORS

Разрешённые origins задаются в `.env` (`CORS_ORIGINS`).

**Настройки:**
- `allow_methods`: GET, POST, PATCH, DELETE, OPTIONS
- `allow_headers`: X-API-Key, Authorization, Content-Type, X-Request-ID, Idempotency-Key
- `expose_headers`: X-Request-ID, X-Process-Time-Ms, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
- `max_age`: 3600

### Примеры на разных языках

См. `docs/examples/`:
- `php/` — Laravel клиент
- `typescript/` — Nest.js клиент
- `python/` — httpx
- `go/` — net/http
- `curl/` — bash-скрипты

### Postman-коллекция

См. `docs/examples/postman/nexus-ai.json`.

---

## Известные ограничения

1. **Скорость на CPU** — 9.3 t/s при 7 потоках. Реальные запросы: single 40–120 сек, оркестрация 2 экспертов ~230 сек.
2. **Rate limit in-memory** — при нескольких API-инстансах счётчики не синхронизируются.
3. **SSE-стрим** — работает через Nginx с `proxy_buffering off`. На клиенте — fetch + ReadableStream (EventSource не подходит для POST).
4. **Nginx `/nginx_status`** — возвращает SPA в debug-режиме, но exporter работает корректно.

---

## Версионирование API

Текущая версия: **v1**.

При breaking changes будет выпущена v2 (`/v2/*`). Старая версия v1 будет поддерживаться минимум 6 месяцев.

---

## Контакты и поддержка

- **GitHub:** https://github.com/Kirill-B2019/NexusAI
- **Документация:** `/opt/nexus-ai/docs/`

---

| KB @CerberRus00 - Nexus Invest Team
