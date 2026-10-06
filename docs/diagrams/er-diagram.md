# ER-диаграмма базы данных

15 таблиц PostgreSQL.

## Схема связей

    ┌──────────────┐         ┌──────────────┐
    │  projects    │────┬───▶│   api_keys   │
    └──────┬───────┘    │    └──────────────┘
           │            │
           │            ├───▶┌──────────────┐
           │            │    │  documents   │
           │            │    └──────┬───────┘
           │            │           │
           │            │           ▼
           │            │    ┌──────────────────┐
           │            │    │ document_chunks  │
           │            │    └──────────────────┘
           │            │
           │            ├───▶┌────────────────┐
           │            │    │ conversations  │
           │            │    └────────┬───────┘
           │            │             │
           │            │             ▼
           │            │    ┌──────────────┐
           │            │    │   messages   │
           │            │    └──────┬───────┘
           │            │           │
           │            │           ├────▶┌──────────────┐
           │            │           │     │  decisions   │
           │            │           │     └──────────────┘
           │            │           │
           │            │           └────▶┌──────────────┐
           │            │                 │    tasks     │
           │            │                 └──────────────┘
           │            │
           │            └───▶┌──────────────┐
           │                 │  audit_log   │
           │                 └──────────────┘
           │
           │  ┌──────────────┐         ┌──────────────────────┐
           └─▶│   experts    │────────▶│expert_prompt_history │
              └──────────────┘         └──────────────────────┘

## Таблицы

### projects
- id UUID PK
- external_id TEXT (уникальный, для Laravel)
- name, description
- created_by_system (admin | laravel | nest | cli)
- metadata JSONB
- created_at, updated_at

### api_keys
- id UUID PK
- project_id UUID FK (NULL для admin-ключей)
- type (admin | project)
- name
- key_hash TEXT UNIQUE (SHA-256)
- key_prefix (первые 12 символов)
- allowed_experts JSONB
- rate_limit_per_min INT
- is_active, expires_at, last_used_at

### experts
- key TEXT PK (например, system_architect)
- name, description, system_prompt
- keywords JSONB
- is_enabled, is_system
- icon, color, sort_order
- metadata JSONB
- deleted_at (soft delete)

### expert_prompt_history
- id BIGSERIAL PK
- expert_key TEXT FK
- old_prompt, new_prompt
- changed_by_actor
- changed_at

### documents
- id UUID PK
- project_id UUID FK
- filename, original_filename
- file_size, mime_type
- status (pending | processing | ready | failed)
- chunks_count, error
- uploaded_via_key_id UUID FK
- processed_at

### document_chunks
- id UUID PK
- document_id UUID FK
- chunk_index
- qdrant_point_id UUID
- text_preview
- char_start, char_end

### conversations
- id UUID PK
- project_id UUID FK
- external_id
- title (автозаголовок из первого user-сообщения)
- metadata JSONB

### messages
- id UUID PK
- conversation_id UUID FK
- role (user | assistant | system)
- expert (для single)
- experts_used JSONB (для оркестрации)
- content, reasoning
- mode
- metadata JSONB

### decisions
- id UUID PK
- project_id UUID FK
- external_id
- title, content
- source_message_id UUID FK
- status (active | superseded | archived)
- metadata JSONB

### tasks
- id UUID PK
- project_id UUID FK
- external_id
- title, description
- status (open | in_progress | done | cancelled)
- priority (low | normal | high | urgent)
- assignee_expert
- source_message_id UUID FK
- due_date
- metadata JSONB

### audit_log
- id BIGSERIAL PK
- actor (admin | project:<uuid>)
- api_key_id, project_id
- action (chat.single, document.upload, ...)
- resource_type, resource_id
- details JSONB
- ip, user_agent, request_id
- created_at

## Каскады

| Родитель | Ребёнок | ON DELETE |
|----------|---------|-----------|
| projects | api_keys | CASCADE |
| projects | documents | CASCADE |
| projects | conversations | CASCADE |
| projects | decisions | CASCADE |
| projects | tasks | CASCADE |
| documents | document_chunks | CASCADE |
| conversations | messages | CASCADE |
| experts | expert_prompt_history | CASCADE |
| messages | decisions.source_message_id | SET NULL |
| messages | tasks.source_message_id | SET NULL |

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

`update_updated_at` на: projects, conversations, decisions, tasks, experts.

---

| KB @CerberRus00 - Nexus Invest Team
