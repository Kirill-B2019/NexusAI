# NEXUS AI — История изменений

Все значимые изменения проекта. Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/).

---

## [Unreleased]

### В работе
- Этап 11: Финальная документация
  - API_REFERENCE.md
  - CHANGELOG.md
  - Mermaid-схемы
  - Примеры на 5 языках
  - Postman-коллекция

---

## [1.0.0] — 2026-10-06

**Первый стабильный релиз NEXUS AI.**

Платформа готова к использованию: 6 экспертов, оркестратор, RAG, диалоги, решения, задачи, мониторинг.

### Добавлено

#### Этап 0 — Подготовка сервера (2026-10-04)
- Ubuntu 26.04.1 LTS на сервере 31.128.38.96
- Docker Engine + Compose Plugin
- Swap 4 ГБ, `vm.swappiness=10`
- UFW firewall: разрешены порты 22, 80, 443, 3000
- Hostname `nexusai` (сохраняется после перезагрузки через cloud-init override)
- Systemd автозапуск `nexus-ai.service`

#### Этап 1 — Модель (2026-10-04)
- llama.cpp server + Qwen3-4B Q4_K_M (2.4 ГБ)
- CPU-инференс, 7 потоков, 2 слота параллельно
- OpenAI-совместимый API на порту 8080
- Скорость: 9.3 t/s (после апгрейда сервера)

#### Этап 2 — RAG-инфраструктура (2026-10-04)
- PostgreSQL 17 (15 таблиц)
- Qdrant (коллекция `project_documents`, 384 dim, Cosine)
- Эмбеддинги: intfloat/multilingual-e5-small
- Отдельный сервис `nexus-embeddings`

#### Этап 3 — API и эксперты (2026-10-04)
- FastAPI приложение (34 эндпоинта)
- 6 экспертов: system_architect, software_engineer, fintech, digital_law, project_scoring, investment_advisor
- Оркестратор: auto / manual / single
- Программный агрегатор + опциональный LLM-агрегатор
- Аутентификация: admin + project ключи
- Аудит всех действий

#### Этап 4 — RAG (2026-10-05)
- Извлечение текста: PDF, DOCX, XLSX, XLS, ODS, CSV, MD, TXT, код
- Чанкинг 500/100 с умным обрезом
- Загрузка документов через `/v1/projects/{id}/documents`
- Изоляция проектов (Qdrant-фильтр + API + каталоги)
- Фильтр по конкретным документам (`document_ids`)
- Отдельный endpoint `/documents/{id}/ask`
- Sanitize null-байтов и control-символов
- Отладочный UI: `/debug/documents.html`

#### Этап 5 — Расширение API (2026-10-05)
- Диалоги и сообщения (cursor-based пагинация)
- Автозаголовок диалога из первого user-сообщения
- Решения (CRUD, привязка к сообщениям)
- Задачи (статусы, приоритеты, исполнители)
- Админ-эндпоинты: stats, projects-usage, system-health, test эксперта
- Аудит с фильтрами (actor, action, project_id, time)
- Найден и исправлен баг: `total` без учёта фильтра `status`

#### Этап 6 — SSE + OpenAPI + CORS + Rate limiting (2026-10-05)
- SSE-стрим `/v1/chat/stream`
- OpenAPI / Swagger UI `/docs`, `/redoc`, `/openapi.json` за admin-ключом
- CORS с точным списком origins
- Rate limiting: 60/мин (project), 600/мин (admin)
- Заголовки `X-RateLimit-Limit`, `X-RateLimit-Remaining`
- Nginx: `proxy_buffering off` для SSE

#### Этап 7 — Стабилизация (2026-10-05)
- Smoke-тесты: 19/19 pass
- Pytest: 22/22 pass
- Regression: 20 кейсов
- Бэкапы: PostgreSQL (ежедневно), Qdrant (еженедельно), конфиги (ежедневно)
- Healthcheck каждые 15 минут
- Cron задачи через systemd-timer

#### Этап 10 — Мониторинг (2026-10-06)
- Prometheus (retention 15 дней)
- Grafana с 2 дашбордами:
  - NEXUS AI — Overview (CPU, RAM, диск, swap, load, сеть)
  - NEXUS AI — API (RPS, latency p95, errors, active requests)
- Экспортёры: node-exporter, postgres-exporter, nginx-exporter
- `/metrics` в FastAPI с 7 типами метрик

### Изменено

- **2026-10-06** — Сервер увеличен до 8 vCPU / 16 ГБ RAM
  - llama.cpp: `--threads 4 → 7`, `--ctx-size 4096 → 8192`
  - Скорость модели: 4.5 → 9.3 t/s (×2.07)
  - Удалён cron-перезапуск модели (RAM хватает)
- **2026-10-06** — ORCH_MAX_TOKENS: 1000 → 700 (короче ответы)
- **2026-10-06** — swappiness восстановлен на 10 после апгрейда

### Исправлено

- **2026-10-05** — `total` в списках не учитывал фильтр `status` (decisions, tasks)
- **2026-10-05** — Null-байты в PDF/DOCX ломали запись в PostgreSQL
- **2026-10-05** — RAG смешивал документы разных проектов (добавлен фильтр `project_id` в Qdrant)
- **2026-10-05** — `query_points` вместо устаревшего `search` в qdrant-client 1.10+
- **2026-10-05** — Nginx кешировал IP API-контейнера (resolver 127.0.0.11)
- **2026-10-05** — API уходил в swap при старом тарифе (исправлено апгрейдом RAM)
- **2026-10-06** — Grafana datasource provisioning не срабатывал автоматически (создан через API)
- **2026-10-06** — `/nginx_status` возвращает SPA (exporter всё равно работает)

### Отложено

- **Этап 8 — LoRA** — обучение адаптеров для 6 экспертов
  - **Причина:** ручной копипаст JSONL ломает многострочный контент
  - **Решение:** реализовать раздел «Датасеты LoRA» в админке
  - **Что готово:** инфраструктура каталогов, промпты, скрипты, Colab-ноутбук, проверка экспорта в GGUF

### Безопасность

- Все секреты в `.env` (chmod 600)
- API-ключи: SHA-256 хеш в БД, plaintext показывается один раз
- Aудит всех критических действий в `audit_log`
- Изоляция проектов на уровне API + Qdrant + файловой системы
- Rate limiting per API-ключ
- UFW: наружу только 22, 80, 443, 3000

### Документация

- 17 файлов в `docs/` (4500+ строк):
  - README.md — индекс
  - ARCHITECTURE.md — C4, потоки, ADR
  - API.md — краткий справочник
  - API_REFERENCE.md — полный справочник (этот релиз)
  - DATA_MODEL.md — ER-диаграмма
  - EXPERTS.md — 6 экспертов
  - RAG.md — работа с документами
  - SSE.md — Server-Sent Events
  - INTEGRATIONS.md — Laravel, Nest.js, Python, PHP, Go
  - UI.md — карта экранов
  - OPERATIONS.md — регламент эксплуатации
  - TESTING.md — smoke, regression, pytest
  - TROUBLESHOOTING.md — частые проблемы
  - MONITORING.md — Prometheus, Grafana
  - QUICKSTART.md — установка за 30 минут
  - decision-log.md — 13 ADR
  - STAGES.md — трекер этапов
  - HANDOFF.md — передача контекста
  - CHANGELOG.md — этот файл

### Технические характеристики

| Параметр | Значение |
|----------|----------|
| Сервер | 8 vCPU Intel Xeon Gold 6140 @ 2.3 GHz |
| RAM | 16 ГБ |
| Диск | 150 ГБ NVMe |
| OS | Ubuntu 26.04.1 LTS |
| Контейнеров | 11 |
| API-эндпоинтов | 34 |
| Экспертов | 6 |
| Таблиц в БД | 15 |
| Скорость модели | 9.3 t/s |
| Single запрос | 40–120 сек |
| Оркестрация 2 эксперта | ~230 сек |

---

## [0.9.0] — 2026-10-04 — Prototype

Первоначальный прототип на 6 ядрах / 12 ГБ.

### Добавлено
- llama.cpp + Qwen3-4B
- FastAPI + PostgreSQL + Qdrant
- 4 базовых эксперта
- Простой оркестратор
- Базовый RAG

### Изменено
- Переход с Qwen3-0.6B на Qwen3-4B (качество)
- Переход с 1.7B на 4B после апгрейда RAM

---

## Планы на будущее

### 1.1.0 — Админка UI
- React/Vue SPA + FastAPI backend
- Раздел «Датасеты LoRA»
- Возможность снять Этап 8 с паузы

### 1.2.0 — LoRA
- Обучение 6 адаптеров (r=16)
- Hot-swap через llama.cpp
- Улучшение качества ответов

### 1.3.0 — 2 сервера
- Разделение Platform / Models
- WireGuard VPN
- Возможность GPU-апгрейда

### 1.4.0 — Алерты
- Telegram-алерты через Alertmanager
- Пороги: RAM > 90%, диск > 85%, 5xx > 5%

### 2.0.0 — Масштабирование
- Kubernetes (при необходимости)
- Managed Kafka для event streaming
- Multi-region

---

## Ссылки

- **GitHub:** https://github.com/Kirill-B2019/NexusAI
- **Документация:** `/opt/nexus-ai/docs/`

---

| KB @CerberRus00 - Nexus Invest Team
