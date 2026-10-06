# NEXUS AI

Внутренняя AI-платформа с 6 экспертами и оркестратором.
API-first, развёрнута на одном сервере, готова к разделению на 2 сервера.

**Полная документация:** [docs/README.md](docs/README.md)

## Что это

- **6 экспертов:** системный архитектор, инженер-программист, финтех, цифровое право, проектный скоринг, инвестиционный советник
- **Оркестратор:** auto / manual / single режимы, параллельное исполнение
- **RAG:** работа с документами PDF/DOCX/XLSX/MD, изоляция проектов
- **SSE-стрим:** потоковая выдача ответов
- **Мониторинг:** Prometheus + Grafana + 2 дашборда
- **34 API-эндпоинта:** аутентификация по API-ключам, rate limiting, аудит

## Стек

| Компонент | Технология |
|-----------|-----------|
| Модель | Qwen3-4B Q4_K_M (llama.cpp, CPU) |
| API | FastAPI + Uvicorn |
| БД | PostgreSQL 17 |
| Векторы | Qdrant |
| Эмбеддинги | multilingual-e5-small |
| Прокси | Nginx |
| Мониторинг | Prometheus + Grafana |

## Требования

- Ubuntu 24.04+ LTS
- 4+ ядра CPU (рекомендуется 8)
- 8+ ГБ RAM (рекомендуется 16)
- 80+ ГБ NVMe
- GPU не требуется

## Установка

См. [docs/QUICKSTART.md](docs/QUICKSTART.md).

## API

- Полный справочник: [docs/API_REFERENCE.md](docs/API_REFERENCE.md)
- Краткий: [docs/API.md](docs/API.md)
- Swagger UI: http://<IP>/api/docs (за admin-ключом)
- Postman-коллекция: [docs/examples/postman/nexus-ai.json](docs/examples/postman/nexus-ai.json)

## Управление

    sudo systemctl status nexus-ai
    cd /opt/nexus-ai && sudo docker compose ps
    cd /opt/nexus-ai && sudo docker compose logs --tail=100 api

## Мониторинг

- Grafana: http://<IP>:3000 (admin / пароль из .env)
- Дашборды: NEXUS AI — Overview, NEXUS AI — API

## Тесты

    /opt/nexus-ai/scripts/smoke.sh
    /opt/nexus-ai/scripts/regression.sh

## Документация

| Раздел | Файл |
|--------|------|
| Установка | docs/QUICKSTART.md |
| Архитектура | docs/ARCHITECTURE.md |
| API Reference | docs/API_REFERENCE.md |
| Интеграции | docs/INTEGRATIONS.md |
| RAG | docs/RAG.md |
| Мониторинг | docs/MONITORING.md |
| Эксплуатация | docs/OPERATIONS.md |
| Примеры кода | docs/examples/ |

Полный индекс: [docs/README.md](docs/README.md).

## История версий

См. [docs/CHANGELOG.md](docs/CHANGELOG.md).

---

| KB @CerberRus00 - Nexus Invest Team
