# NEXUS AI — Troubleshooting

Частые проблемы и решения.

## API

### 502 Bad Gateway

**Симптом:** `curl /api/health` возвращает HTML-ошибку Nginx.

**Причины:**
1. Контейнер `nexus-api` в статусе `Restarting`
2. Nginx кеширует старый IP API

**Диагностика:**

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml ps
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs --tail=30 api

**Решение:**

    # Если API Restarting — смотрим причину в логах
    # Если API Up, но всё равно 502 — перезапускаем Nginx
    sudo docker compose restart nginx

    # Проверяем
    curl -s -w "\nHTTP:%{http_code}\n" http://localhost/api/health

### 401 Unauthorized

**Причины:**
- Заголовок `X-API-Key` не передан
- Ключ невалидный
- Ключ отозван (`is_active=false`)
- Ключ истёк (`expires_at` в прошлом)

**Проверка:**

    curl -s -i -H "X-API-Key: $ADMIN_KEY" http://localhost/api/v1/experts | head -5

### 403 Forbidden

**Причины:**
- Project-ключ пытается достучаться до admin-эндпоинта
- Project-ключ обращается к чужому проекту
- Project-ключ запрашивает запрещённого эксперта (allowed_experts)

**Решение:** использовать admin-ключ или правильный project-ключ.

### 429 Too Many Requests

**Симптом:** `{"detail":"Rate limit exceeded"}`.

**Причина:** превышен лимит:
- admin — 600/мин
- project — 60/мин

**Проверка заголовков:**

    curl -s -i -H "X-API-Key: $ADMIN_KEY" http://localhost/api/v1/experts 2>&1 | grep -i x-ratelimit

**Решение:** подождать `Retry-After` секунд.

### 504 Gateway Timeout

**Симптом:** долгий запрос (>30 мин) обрывается.

**Причины:**
- thinking=true с очень длинным промптом
- Много экспертов (6+) на CPU

**Решение:**
- Отключить thinking
- Уменьшить число экспертов
- Увеличить таймауты в Nginx

---

## Модель (llama.cpp)

### Модель в статусе Restarting

**Диагностика:**

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs --tail=50 model-server

**Частые причины:**

1. **`--mlock` не поддерживается** → заменить на `--load-mode mmap+mlock`
2. **Недостаточно RAM** → уменьшить `--ctx-size` или `--parallel`
3. **Модель не найдена** → проверить путь `/models/*.gguf`
4. **Битый файл модели** → проверить `sha256sum`

### Модель отвечает медленно

**Симптом:** скорость < 3 ток/сек.

**Диагностика:**

    sudo docker compose logs --tail=20 model-server | grep "t/s"
    free -h

**Решения:**
- Убедиться, что `--threads 4` (не 2 и не 8)
- Проверить, не свопится ли: `free -h` и `vmstat 1`
- Уменьшить `--ctx-size` до 2048

### Модель падает с OOM

**Проверка:**

    sudo dmesg | grep -i "killed process"

**Решение:**
- Уменьшить `mem_limit` в compose, чтобы Docker останавливал раньше OOM-killer'а
- Уменьшить `--ctx-size` или `--parallel`

---

## RAG

### Документ в статусе `failed`

**Проверка ошибки:**

    sudo docker exec nexus-postgres psql -U nexusai -d nexusai -P pager=off -c "
    SELECT filename, error FROM documents WHERE status='failed';
    "

**Частые причины:**

| Ошибка | Решение |
|--------|---------|
| `invalid byte sequence for encoding UTF8: 0x00` | Null-байты — обновить `extractors.py` до версии с `_sanitize` |
| `Пустой текст после извлечения` | PDF-скан без текстового слоя — нужен OCR |
| `pdfplumber не установлен` | Пересобрать образ API |
| `Файл больше 50 МБ` | Уменьшить файл или увеличить `MAX_FILE_SIZE` |
| `Unsupported extension` | Добавить формат в `EXT_TO_MIME` |

### RAG не находит документы

**Симптом:** `rag_used: false` при запросе с `use_rag: true` и `project_id`.

**Проверка 1 — эмбеддинги:**

    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \
      -X POST http://nexus-embeddings:8001/embed \
      -H "Content-Type: application/json" \
      -d '{"texts":["тест"],"prefix":"query"}' | jq '.dim'

**Ожидается:** `384`

**Проверка 2 — точки в Qdrant:**

    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \
      http://nexus-qdrant:6333/collections/project_documents | jq '.result.points_count'

**Проверка 3 — прямой поиск:**

    sudo docker exec nexus-api python3 -c "
    import asyncio, embeddings_client, qdrant_service
    async def test():
        vec = await embeddings_client.embed_query('тест')
        r = qdrant_service.search('PROJECT_ID', vec, top_k=3, score_threshold=0.0)
        print(f'Найдено: {len(r)}')
    asyncio.run(test())
    "

### RAG смешивает документы разных проектов

**Причина:** фильтр `project_id` не применился.

**Проверка:**

    sudo docker exec nexus-api python3 -c "
    import qdrant_service
    r = qdrant_service.search('PROJECT_ID', [0.0]*384, top_k=100, score_threshold=0.0)
    print(set(x['document_id'] for x in r))
    "

Все `document_id` должны принадлежать одному проекту.

---

## База данных

### PostgreSQL не запускается

    sudo docker compose logs --tail=50 postgres

**Частые причины:**
- Пароль в `.env` не совпадает с тем, что был при инициализации → **удалить том и пересоздать** (потеря данных!)
- Занят порт 5432

### Ошибка миграции

    sudo docker exec nexus-postgres psql -U nexusai -d nexusai -P pager=off -c "\dt"

Если таблицы нет — применить схему:

    sudo docker exec -i nexus-postgres psql -U nexusai -d nexusai < /opt/nexus-ai/scripts/init-db.sql

### Дубликаты в /etc/hosts

**Симптом:** `hostname` выводит одно, а в `/etc/hosts` — несколько строк.

**Решение:**

    sudo sed -i '/127.0.1.1/d' /etc/hosts
    echo "127.0.1.1 nexusai" | sudo tee -a /etc/hosts

### hostname сбрасывается после перезагрузки

**Причина:** cloud-init.

**Решение:**

    echo "preserve_hostname: true" | sudo tee /etc/cloud/cloud.cfg.d/99-preserve-hostname.cfg
    sudo hostnamectl set-hostname nexusai

---

## Nginx

### 404 на /debug/*

**Причина:** Nginx не находит файл.

    ls -la /opt/nexus-ai/nginx/debug/
    sudo docker exec nexus-nginx ls -la /usr/share/nginx/html/debug/

**Решение:** проверить, что volume смонтирован, перезапустить Nginx.

### SSE-стрим приходит одним куском

**Симптом:** все токены появляются одновременно в конце.

**Причина:** Nginx буферизует.

**Решение:**

    grep "proxy_buffering" /opt/nexus-ai/nginx/default.conf
    # Должно быть: proxy_buffering off;

Если нет — добавить и перезапустить:

    sudo docker compose restart nginx

---

## Docker

### "No space left on device"

    df -h
    sudo docker system df

**Решение:**

    # Удалить неиспользуемые образы
    sudo docker image prune -a

    # Удалить неиспользуемые volumes (ОСТОРОЖНО)
    sudo docker volume prune

    # Логи могут занимать место
    sudo docker system prune

### Контейнер запускается, но сразу падает

    sudo docker compose logs <service-name> | tail -30

Типичные причины:
- **ImportError** — модуль не в COPY Dockerfile
- **SyntaxError** — Python/bash синтаксис
- **OOM** — превышен mem_limit

### Кеш сборки не обновляется

**Симптом:** пересборка не отражает изменения в файлах.

**Решение:**

    sudo docker compose build --no-cache api
    sudo docker compose up -d api

---

## Производительность

### Всё медленно

    free -h
    sudo docker stats --no-stream
    uptime

**Что проверить:**
- Свободная RAM (>1 ГБ)
- Load average (< 4 для 6-ядерного)
- Swap (не активно)
- Диск (не забит)

### Долгие ответы модели

На CPU ожидаемо:
- single + thinking=false: 30–120 сек
- single + thinking=true: 3–5 мин
- orchestration 3 эксперта: 4–8 мин

**Ускорение:**
- Уменьшить `max_tokens`
- Отключить thinking для простых вопросов
- Уменьшить число экспертов
- Перейти на GPU (Этап 9)

---

## Быстрый чек-лист диагностики

Когда что-то не работает:

    # 1. Все ли контейнеры Up?
    sudo docker compose ps

    # 2. Smoke-тесты
    /opt/nexus-ai/scripts/smoke.sh

    # 3. System health через API
    curl -s -H "X-API-Key: $ADMIN_KEY" http://localhost/api/v1/admin/system-health | jq

    # 4. Логи упавшего сервиса
    sudo docker compose logs --tail=50 <service>

    # 5. RAM / диск
    free -h && df -h /
