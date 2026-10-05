# NEXUS AI — HANDOFF

Точка передачи контекста. Обновлено: 2026-10-05.

## Где мы сейчас

- **Этапы 0–7:** завершены ✅
- **Этап 8 (LoRA):** начат, на шаге 8.1 (генерация датасетов)
- **Этапы 9–11:** не начаты

## Что работает

- **API:** 34 эндпоинта, FastAPI на порту 8000
- **Модель:** Qwen3-4B Q4_K_M, llama.cpp, 2 слота, ctx 4096
- **6 экспертов:** system_architect, software_engineer, fintech, digital_law, project_scoring, investment_advisor
- **Оркестратор:** auto / manual / single
- **RAG:** Qdrant, embeddings e5-small, изоляция проектов
- **Диалоги, решения, задачи, аудит:** полностью CRUD
- **SSE-стрим:** /v1/chat/stream
- **Админ:** stats, usage, system-health, audit
- **Rate limiting:** 60/мин (project), 600/мин (admin)
- **Smoke/regression/pytest:** 19/19, 3/3, 22/22

## Сервер

- IP: 31.128.38.96
- 6 ядер, 12 ГБ RAM, 150 ГБ NVMe
- 6 Docker-контейнеров
- Nginx на 80, HTTPS не настроен

## Этап 8 — где остановились

### Что пробовали

1. ✅ Создана инфраструктура `lora/` (7 подкаталогов)
2. ✅ Созданы 6 промптов по 60 вопросов (`lora/prompts/*.json`)
3. ✅ Скрипт `scripts/lora_generate.sh` — генерирует JSONL через API
4. ✅ Colab T4 работает, smoke-тренировка прошла (loss 0.97 → 0.32)
5. ✅ LoRA → GGUF конвертация проверена (63 МБ)

### Что не сработало

- **Параллельная генерация через API:** 2 процесса → swap → 0.43 t/s
- **Серверная генерация:** 60 пар на эксперта = 3 часа (медленно)
- **Качество:** сырое, с утечками

### Решение (принято 2026-10-05)

**Генерация датасетов — вручную в новом чате с Claude.**

- 300 пар на эксперта (вместо 60)
- 6 экспертов × 300 = **1800 пар**
- Формат JSONL (messages + metadata)
- Файлы: `/opt/nexus-ai/lora/datasets/manual/{expert}.jsonl`

## Планы этапов 8–11

### Этап 8 (текущий)

| # | Шаг | Статус |
|---|-----|--------|
| 8.1 | Генерация датасетов (ручная) | ⏳ в работе |
| 8.2 | Обучение LoRA в Colab | ⏸ ждёт датасет |
| 8.3 | Конвертация в GGUF | ⏸ |
| 8.4 | Перенос на сервер, hot-swap | ⏸ |
| 8.5 | Оценка через regression.sh | ⏸ |
| 8.6 | docs/LORA.md | ⏸ |

### Этап 9 — 2 сервера

- Сервер A: Platform (API, PostgreSQL, Qdrant, Embeddings)
- Сервер B: Models (llama.cpp + LoRA)
- Изменение `MODEL_SERVER_URL` в `.env`
- VPN (WireGuard) + firewall

### Этап 10 — Мониторинг

- Prometheus + Grafana
- Алерты в Telegram
- Метрики: RAM, CPU, диск, токены/сек, 4xx/5xx

### Этап 11 — Финальная документация

- Сборка всех документов
- Mermaid-схемы
- Примеры на 4 языках

## Формат датасетов

JSONL, одна строка — один JSON:

```json
{"messages":[{"role":"system","content":"Ты — сотрудник NEXUS AI. Ты — SYSTEM_ARCHITECT..."},{"role":"user","content":"<вопрос>"},{"role":"assistant","content":"<ответ 400-800 слов>"}],"metadata":{"expert":"system_architect","source":"manual","variant":"1"}}
