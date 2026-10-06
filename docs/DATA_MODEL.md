# NEXUS AI — Модель данных

## Обзор

15 таблиц в PostgreSQL. Центральная сущность — project, к нему привязаны все остальные данные.

## ER-диаграмма (текстовая)

    projects (проекты)
      ├─ api_keys (ключи доступа)
      ├─ documents (документы)
      │   └─ document_chunks (чанки)
      ├─ conversations (диалоги)
      │   └─ messages (сообщения)
      │       ├─ decisions.source_message_id (ссылка)
      │       └─ tasks.source_message_id (ссылка)
      ├─ decisions (решения)
      ├─ tasks (задачи)
      └─ audit_log.project_id (ссылка)

    experts (реестр экспертов, независимая таблица)
      └─ expert_prompt_history (история изменений промптов)

## Таблицы

### projects
- id UUID PK
- external_id TEXT (ID из Laravel/Nest.js, уникальный если не NULL)
- name, description
- created_by_system (admin | laravel | nest | cli)
- metadata JSONB
- created_at, updated_at

### api_keys
- id UUID PK
- project_id UUID FK (NULL для admin-ключей)
- type (admin | project)
- name
- key_hash TEXT UNIQUE (SHA-256 от ключа)
- key_prefix TEXT (первые 12 символов, для UI)
- is_active BOOL
- allowed_experts JSONB (NULL = все)
- rate_limit_per_min INT (по умолчанию 60)
- created_at, last_used_at, expires_at

### experts
- key TEXT PK (например, system_architect)
- name, description
- system_prompt TEXT
- keywords JSONB (массив)
- is_enabled BOOL
- is_system BOOL (защита от удаления)
- icon, color, sort_order
- metadata JSONB
- created_at, updated_at, deleted_at (soft delete)

### expert_prompt_history
- id BIGSERIAL PK
- expert_key TEXT FK
- old_prompt, new_prompt TEXT
- changed_by_actor TEXT
- changed_at

### documents
- id UUID PK
- project_id UUID FK
- filename (safe), original_filename
- file_size, mime_type
- status (pending | processing | ready | failed)
- chunks_count INT
- error TEXT
- uploaded_via_key_id UUID FK (api_keys)
- created_at, processed_at

### document_chunks
- id UUID PK
- document_id UUID FK
- chunk_index INT
- qdrant_point_id UUID
- text_preview TEXT
- char_start, char_end INT
- created_at

### conversations
- id UUID PK
- project_id UUID FK
- external_id TEXT
- title (автозаполнение при первом user-сообщении)
- metadata JSONB
- created_at, updated_at

### messages
- id UUID PK
- conversation_id UUID FK
- role (user | assistant | system)
- expert TEXT (для single)
- experts_used JSONB (для оркестрации)
- content, reasoning TEXT
- mode TEXT
- metadata JSONB
- created_at

### decisions
- id UUID PK
- project_id UUID FK
- external_id TEXT
- title, content TEXT
- source_message_id UUID FK (messages)
- status (active | superseded | archived)
- metadata JSONB
- created_at, updated_at

### tasks
- id UUID PK
- project_id UUID FK
- external_id TEXT
- title, description
- status (open | in_progress | done | cancelled)
- priority (low | normal | high | urgent)
- assignee_expert TEXT
- source_message_id UUID FK (messages)
- due_date DATE
- metadata JSONB
- created_at, updated_at

### audit_log
- id BIGSERIAL PK
- actor TEXT (admin | project:<uuid> | laravel | system)
- api_key_id UUID FK
- project_id UUID FK
- action TEXT (chat.single, document.upload, ...)
- resource_type, resource_id
- details JSONB
- ip, user_agent, request_id
- created_at

## Индексы

- projects.external_id (уникальный)
- api_keys.key_hash, api_keys.project_id
- experts.is_enabled (partial WHERE deleted_at IS NULL)
- documents.project_id, documents.status
- document_chunks.document_id
- conversations.project_id
- messages.conversation_id + created_at
- decisions.project_id + status
- tasks.project_id + status
- audit_log.created_at DESC, actor, project_id

## Триггеры

Триггер update_updated_at на: users (нет), projects, conversations, decisions, tasks, experts.

## Soft delete

- experts — через deleted_at
- Остальное — hard delete с каскадом (ON DELETE CASCADE)

## Связи

- projects → documents, conversations, decisions, tasks, api_keys, audit_log
- conversations → messages (CASCADE)
- documents → document_chunks (CASCADE)
- messages ← decisions.source_message_id, tasks.source_message_id
- experts → expert_prompt_history (CASCADE)

---

| KB @CerberRus00 - Nexus Invest Team
