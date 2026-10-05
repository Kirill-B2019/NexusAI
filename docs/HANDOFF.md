## NEXUS AI — HANDOFF

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

### Этап 8 — статус: ОТЛОЖЕН

**Что сделано:**
- Инфраструктура каталогов `lora/` (7 подкаталогов)
- 6 промптов по 60 вопросов (`lora/prompts/*.json`)
- Скрипты: `lora_check_duplicate.py`, `lora_coverage.py`, `lora_split_glued.py`, `lora_json_to_jsonl.py`
- Карта тем `topics.json`
- Colab T4 работает, smoke-тренировка прошла (loss 0.97 → 0.32)
- Экспорт LoRA → GGUF проверен (63 МБ)
- 5 эталонных пар для `system_architect` (в датасете manual/)

**Что не сработало:**
- Копипаст JSONL из чата → nano ломает многострочный контент
- Требуется решить через UI/скрипт, а не вручную

**Решение (принято 2026-10-05):**
Отложить Этап 8 до реализации админки.
В админке будет раздел «Датасеты LoRA» с:
- Импорт JSON/JSONL через форму
- Валидация на дубликаты и структуру
- Экспорт в формат для Colab
- Запуск обучения (Modal/RunPod)
- Управление версиями адаптеров

**Датасет на текущий момент:**
- system_architect: 5 пар (эталон)
- Остальные: пусто

**Приоритет: НИЗКИЙ.**
Пока не убедимся, что LoRA даёт ощутимый эффект, UI не делаем.

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
