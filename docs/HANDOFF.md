# NEXUS AI — HANDOFF

Точка передачи контекста. Обновлено: 2026-10-05.

## Где мы сейчас

- **Этапы 0–7:** завершены
- **Этап 8 (LoRA):** отложен до реализации админки
- **Этап 10 (Мониторинг):** завершён (2026-10-05)
- **Этап 9 (2 сервера):** не начат
- **Этап 11 (финальная документация):** не начат

## Что работает

- **API:** 34 эндпоинта, FastAPI на порту 8000
- **Модель:** Qwen3-4B Q4_K_M, llama.cpp, 7 threads, 2 слота, ctx 4096/слот, **9.3 t/s**
- **6 экспертов:** system_architect, software_engineer, fintech, digital_law, project_scoring, investment_advisor
- **Оркестратор:** auto / manual / single + программный агрегатор
- **RAG:** Qdrant, embeddings e5-small, изоляция проектов, фильтр по документам
- **Диалоги, решения, задачи, аудит:** полностью CRUD
- **SSE-стрим:** /v1/chat/stream
- **Админ:** stats, usage, system-health, audit
- **Rate limiting:** 60/мин (project), 600/мин (admin)
- **Мониторинг:** Prometheus + Grafana + 2 дашборда (Overview + API), 5 targets up
- **Smoke/regression/pytest:** 19/19, 3/3, 22/22

## Сервер

- **IP:** 31.128.38.96
- **CPU:** 8 vCPU (Intel Xeon Gold 6140 @ 2.3 GHz), без гипертрединга
- **RAM:** 16 ГБ
- **Диск:** 150 ГБ NVMe (27 ГБ занято)
- **OS:** Ubuntu 26.04.1 LTS
- **Hostname:** nexusai
- **Контейнеров:** 11 (6 основных + 5 мониторинга)
- **Nginx:** порт 80
- **Grafana:** порт 3000
- **Llama.cpp:** `--threads 7`, `--parallel 2`, `--ctx-size 8192` (4096 на слот)
- **Скорость модели:** **9.3 t/s** на CPU (было 4.5 на 6 ядрах)
- **Реальная скорость API:**
  - Single (короткий ответ): 40–90 сек
  - Single (длинный ответ): 90–120 сек
  - Оркестрация 2 эксперта: 200–240 сек
  - Оркестрация 3 эксперта: ~350 сек
- **Swap:** 4 ГБ, swappiness=10, используется 0B
- **Ограничение:** CPU-инференс упёрся в потолок. Дальнейшее ускорение — только GPU

## Структура проекта

    /opt/nexus-ai/
    ├── api/              # FastAPI приложение
    │   ├── main.py
    │   ├── auth.py
    │   ├── middleware.py
    │   ├── metrics.py
    │   ├── experts_service.py
    │   ├── orchestrator.py
    │   ├── extractors.py
    │   ├── chunker.py
    │   ├── embeddings_client.py
    │   ├── qdrant_service.py
    │   ├── rag.py
    │   ├── rate_limit.py
    │   ├── Dockerfile
    │   └── routers/      # 11 роутеров
    ├── embeddings/       # Сервис эмбеддингов
    ├── nginx/            # Nginx конфиг + UI
    │   └── debug/        # Отладочные страницы
    ├── monitoring/
    │   ├── prometheus/
    │   └── grafana/
    ├── docs/             # 16 документов
    ├── lora/             # LoRA инфраструктура
    ├── scripts/          # Скрипты
    ├── tests/            # Тесты
    ├── backups/          # Бэкапы
    ├── models/           # Qwen3-4B GGUF
    ├── docker-compose.yml
    ├── .env              # Секреты (chmod 600)
    └── README.md

## Команды управления

    # Статус
    sudo systemctl status nexus-ai
    cd /opt/nexus-ai && sudo docker compose ps

    # Логи
    cd /opt/nexus-ai && sudo docker compose logs --tail=50 api
    cd /opt/nexus-ai && sudo docker compose logs --tail=50 model-server

    # Перезапуск
    sudo systemctl restart nexus-ai
    cd /opt/nexus-ai && sudo docker compose restart <service>

    # Тесты
    /opt/nexus-ai/scripts/smoke.sh
    /opt/nexus-ai/scripts/regression.sh

    # Бэкапы
    /opt/nexus-ai/scripts/backup-postgres.sh
    /opt/nexus-ai/scripts/backup-qdrant.sh
    /opt/nexus-ai/scripts/backup-config.sh

## Мониторинг

- **Grafana:** http://31.128.38.96:3000
- **Логин:** admin
- **Пароль:** в .env → GRAFANA_PASSWORD
- **Дашборды:**
  - NEXUS AI — Overview: /d/nexus-overview
  - NEXUS AI — API: /d/nexus-api

## Аутентификация

- **Admin-ключ:** в `.env` → ADMIN_API_KEY (полный доступ)
- **Project-ключи:** создаются через API, привязаны к проекту
- **Заголовки:** X-API-Key: <key> или Authorization: Bearer <key>

## Этап 8 — LoRA (отложен)

### Что сделано

- Инфраструктура каталогов `lora/` (7 подкаталогов)
- 6 промптов по 60 вопросов (`lora/prompts/*.json`)
- Скрипты: `lora_check_duplicate.py`, `lora_coverage.py`, `lora_split_glued.py`, `lora_json_to_jsonl.py`
- Карта тем `topics.json`
- Colab T4 проверен, smoke-тренировка прошла (loss 0.97 → 0.32)
- Экспорт LoRA → GGUF проверен (63 МБ)
- 5 эталонных пар для `system_architect` в `lora/datasets/manual/`

### Проблема

Копипаст JSONL из чата в nano ломает многострочный контент
(SSH-клиент превращает `\n` внутри строк в реальные переносы).

### Решение

Отложить Этап 8 до реализации админки.
Раздел «Датасеты LoRA» в админке обеспечит:
- Импорт JSON/JSONL через форму
- Валидацию на дубликаты и структуру
- Экспорт в формат для Colab
- Управление версиями адаптеров

### Приоритет

НИЗКИЙ. Пока не убедимся, что LoRA даёт ощутимый эффект, UI не делаем.

## Этап 9 — план (2 сервера)

### Архитектура

    ┌─────────────────────────┐     ┌─────────────────────────┐
    │ Сервер A — Platform     │     │ Сервер B — Models       │
    │                         │     │                         │
    │ Nginx → API             │────▶│ llama.cpp (Qwen3-4B)    │
    │  ├─ PostgreSQL          │HTTP │ llama.cpp (LoRA)        │
    │  ├─ Qdrant              │     │                         │
    │  ├─ Embeddings          │     │ GPU-ready (опционально) │
    │  ├─ Orchestrator        │     │                         │
    │  └─ Monitoring          │     │                         │
    └─────────────────────────┘     └─────────────────────────┘

### Шаги

1. Подготовить сервер B (Ubuntu + Docker + llama.cpp)
2. Настроить WireGuard VPN между A и B
3. Ограничить доступ к модели (firewall: только IP сервера A)
4. API-ключ для модели через `--api-key` в llama.cpp
5. Изменить `MODEL_SERVER_URL` в `.env` сервера A
6. Обновить `healthcheck.sh` для проверки сервера B
7. Протестировать

### Перенос данных

**Ничего не теряется:** все данные (PostgreSQL, Qdrant, документы) остаются на сервере A. Переезжает только модель.

## Ключевые файлы

| Файл | Назначение |
|------|-----------|
| `/opt/nexus-ai/api/main.py` | Точка входа FastAPI |
| `/opt/nexus-ai/api/auth.py` | Проверка API-ключей |
| `/opt/nexus-ai/api/metrics.py` | Prometheus метрики |
| `/opt/nexus-ai/api/orchestrator.py` | Роутер + executor |
| `/opt/nexus-ai/api/rag.py` | RAG-поиск |
| `/opt/nexus-ai/docker-compose.yml` | Все 11 сервисов |
| `/opt/nexus-ai/nginx/default.conf` | Nginx конфиг |
| `/opt/nexus-ai/.env` | Секреты |
| `/opt/nexus-ai/monitoring/prometheus/prometheus.yml` | Prometheus |
| `/opt/nexus-ai/scripts/smoke.sh` | Smoke-тесты |

## Документация

Все документы в `/opt/nexus-ai/docs/`:

- README.md — индекс
- ARCHITECTURE.md — C4, потоки данных, ADR
- API.md — справочник эндпоинтов
- DATA_MODEL.md — ER-диаграмма
- EXPERTS.md — 6 экспертов, добавление новых
- RAG.md — чанкинг, эмбеддинги, поиск
- SSE.md — Server-Sent Events
- INTEGRATIONS.md — Laravel, Nest.js, Python, PHP
- UI.md — карта экранов, компоненты
- OPERATIONS.md — регламент эксплуатации
- TESTING.md — smoke, regression, pytest
- TROUBLESHOOTING.md — частые проблемы
- MONITORING.md — Prometheus, Grafana
- decision-log.md — ADR (13 решений)
- STAGES.md — этот трекер
- HANDOFF.md — этот файл

## Git

- **Локальный репозиторий:** /opt/nexus-ai/.git
- **Удалённый:** https://github.com/Kirill-B2019/NexusAI
- **Ветка:** main
- **Файл `.env`** — в .gitignore, не коммитится
- **Папки models/, backups/, loras/** — в .gitignore

## Переменные окружения (.env)

    POSTGRES_PASSWORD=...
    QDRANT_URL=http://nexus-qdrant:6333
    MODEL_SERVER_URL=http://nexus-model:8080
    MODEL_TIMEOUT=900
    EXPERT_TIMEOUT=900
    ADMIN_API_KEY=...
    ADMIN_EMAIL=...
    ADMIN_PASSWORD=...
    ADMIN_FULL_NAME=...
    DATABASE_URL=postgresql://nexusai:...@nexus-postgres:5432/nexusai
    QDRANT_URL=http://nexus-qdrant:6333
    EMBEDDINGS_URL=http://nexus-embeddings:8001
    CORS_ORIGINS=http://localhost:3000,http://localhost:8000,http://localhost:5173,http://31.128.38.96
    LOG_LEVEL=info
    MODEL_API_KEY=
    DOCUMENTS_DIR=/app/data/documents
    GRAFANA_PASSWORD=...

## Что делать в новом чате

При передаче контекста новому чату:

1. Открыть этот файл (`/opt/nexus-ai/docs/HANDOFF.md`)
2. Открыть `docs/STAGES.md`
3. Сказать: «Продолжаем NEXUS AI. Текущий этап: X. См. HANDOFF.md и STAGES.md.»

## Известные проблемы

1. **`/nginx_status`** возвращает SPA вместо метрик — exporter всё равно работает (проверить позже)
2. **Grafana provisioning** не сработал автоматически — datasource создан через API (UID `prometheus`)
3. **Nexus-api target** в Prometheus был down до добавления `/metrics` — теперь up
4. **Пиковая нагрузка 76% CPU** при генерации датасетов — норма, но следим за swap

## Контакты

- **Сервер:** root@31.128.38.96
- **Часовой пояс:** UTC
- **GitHub:** https://github.com/Kirill-B2019/NexusAI
