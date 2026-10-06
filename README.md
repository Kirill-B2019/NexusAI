# NEXUS AI

Полная документация: [docs/README.md](docs/README.md)

Внутренняя AI-платформа с 6 экспертами и оркестратором. API-first: фронт (Laravel + Nest.js) и другие системы подключаются по HTTP.

## Архитектура

- **Модель:** Qwen3-4B Q4_K_M (llama.cpp, CPU, 2 слота)
- **API:** FastAPI + Uvicorn
- **БД:** PostgreSQL 17
- **Векторы:** Qdrant
- **Эмбеддинги:** multilingual-e5-small
- **Прокси:** Nginx
- **Аутентификация:** API-ключи (admin + project)

## Эксперты (6)

| Ключ | Роль |
|------|------|
| system_architect | Системный архитектор |
| software_engineer | Инженер-программист |
| fintech | Финтех-эксперт |
| digital_law | Эксперт по цифровому праву |
| project_scoring | Эксперт по проектному скорингу |
| investment_advisor | Инвестиционный советник |

## API (v1)

### Публичные
- `GET /api/health`
- `GET /api/version`

### С API-ключом
- `GET /api/v1/experts` — список активных экспертов
- `POST /api/v1/chat` — чат (single / auto / manual)
- `POST /api/v1/route` — предпросмотр выбора экспертов
- `POST /api/v1/projects/{id}/documents` — загрузка документов
- `GET /api/v1/conversations/{id}/messages` — история диалога
- `POST /api/v1/decisions` — решения
- `POST /api/v1/tasks` — задачи

### Admin-only
- `POST /api/v1/experts` — создать эксперта
- `PATCH /api/v1/experts/{key}` — изменить
- `POST /api/v1/experts/{key}/enable|disable`
- `POST /api/v1/projects` — создать проект
- `POST /api/v1/projects/{id}/keys` — выдать API-ключ
- `GET /api/v1/audit` — журнал аудита

## Аутентификация

**Admin-ключ** — в `.env` (`ADMIN_API_KEY`):
    curl -H "X-API-Key: $ADMIN_KEY" https://api.../v1/experts

**Project-ключ** — выдаётся через `/v1/projects/{id}/keys`, привязан к проекту.

**Заголовки:**
- `Authorization: Bearer <key>` или
- `X-API-Key: <key>`

## Управление

    sudo systemctl status nexus-ai
    docker compose -f /opt/nexus-ai/docker-compose.yml ps
    docker compose -f /opt/nexus-ai/docker-compose.yml logs -f api
    sudo systemctl restart nexus-ai

## Бэкапы

- PostgreSQL: ежедневно 03:00
- Qdrant: каждое воскресенье 03:30
- Конфигурация: ежедневно 04:00
- Healthcheck: каждые 15 минут

Каталог: `/opt/nexus-ai/backups/`

## Файлы

- `/opt/nexus-ai/api/main.py` — точка входа
- `/opt/nexus-ai/api/auth.py` — проверка ключей
- `/opt/nexus-ai/api/middleware.py` — Request-ID, audit
- `/opt/nexus-ai/api/experts_service.py` — работа с экспертами
- `/opt/nexus-ai/api/orchestrator.py` — роутер + executor
- `/opt/nexus-ai/api/routers/` — эндпоинты по доменам
- `/opt/nexus-ai/scripts/` — бэкапы, healthcheck, versions

## Версии

См. `VERSIONS.md`.

---

| KB @CerberRus00 - Nexus Invest Team
