# NEXUS AI — Эксплуатация

Регламент ежедневной работы с системой.

## Ежедневно (утро)

    # 1. Smoke-тесты (автоматически в 08:00 через cron, но можно и вручную)
    /opt/nexus-ai/scripts/smoke.sh

    # 2. Healthcheck (в cron каждые 15 минут)
    tail -20 /opt/nexus-ai/backups/healthcheck.log

    # 3. Проверить ошибки в API за сутки
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs --since 24h api | grep -iE "error|exception" | head -20

## Еженедельно

    # Проверка места на диске
    df -h /

    # Размер бэкапов
    du -sh /opt/nexus-ai/backups/*

    # Проверка бэкапов Qdrant (по воскресеньям 03:30)
    ls -lh /opt/nexus-ai/backups/qdrant/

    # Проверка audit-log на аномалии
    sudo docker exec nexus-postgres psql -U nexusai -d nexusai -P pager=off -c "
    SELECT actor, action, COUNT(*) FROM audit_log
    WHERE created_at > NOW() - INTERVAL '7 days'
    GROUP BY actor, action ORDER BY COUNT(*) DESC LIMIT 20;
    "

    # Аудит чата, LoRA и обучения с метаданными запроса
    sudo docker exec nexus-postgres psql -U nexusai -d nexusai -P pager=off -c "
    SELECT created_at, actor, action, ip, request_id, details FROM audit_log
    WHERE action LIKE 'chat.%' OR action LIKE 'lora_adapter.%'
       OR action LIKE 'training_dataset.%'
    ORDER BY created_at DESC LIMIT 50;
    "

## Ежемесячно

- Обновление Docker-образов (postgres, qdrant, nginx)
- Проверка свободного места на NVMe
- Аудит API-ключей (удалить неиспользуемые)
- Просмотр метрик system-health

## Ежеквартально

- **Тест восстановления из бэкапа** на отдельной машине
- Проверка, что все systemd-сервисы работают
- Обновление документации

---

## Бэкапы

### Расположение

    /opt/nexus-ai/backups/
    ├── postgres/     — pg_dump, ежедневно 03:00, retention 14 дней
    ├── qdrant/       — snapshots коллекций, по воскресеньям 03:30, retention 30 дней
    ├── config/       — docker-compose, .env, api/, nginx/, systemd — ежедневно 04:00, retention 30 дней
    ├── backup.log    — журнал
    └── healthcheck.log — проверки

### Восстановление PostgreSQL

    # Последний дамп
    LATEST=$(ls -t /opt/nexus-ai/backups/postgres/*.sql.gz | head -1)
    echo "Восстанавливаем из: $LATEST"

    # Восстановление
    gunzip -c "$LATEST" | \
      docker exec -i nexus-postgres psql -U nexusai -d nexusai

### Восстановление Qdrant

    # Снапшот
    SNAP=$(ls -t /opt/nexus-ai/backups/qdrant/*.snapshot | head -1)

    # Копируем в контейнер
    docker cp "$SNAP" nexus-qdrant:/tmp/snapshot.snapshot

    # Восстановление через API (замените COLLECTION_NAME на project_documents)
    docker exec nexus-qdrant curl -X POST \
      "http://localhost:6333/collections/project_documents/snapshots/upload" \
      -F "snapshot=@/tmp/snapshot.snapshot"

    # Проверка
    docker exec nexus-qdrant curl -s \
      http://localhost:6333/collections/project_documents | jq '.result.points_count'

### Восстановление конфигурации

    # Распаковка
    LATEST_CFG=$(ls -t /opt/nexus-ai/backups/config/*.tar.gz | head -1)
    mkdir -p /tmp/restore && tar -xzf "$LATEST_CFG" -C /tmp/restore/

    # Просмотр
    ls -la /tmp/restore/

    # Копирование нужных файлов вручную

---

## Управление сервисами

### Запуск / остановка

    # Все сервисы (через systemd)
    sudo systemctl start nexus-ai
    sudo systemctl stop nexus-ai
    sudo systemctl restart nexus-ai

    # Отдельный контейнер
    cd /opt/nexus-ai
    sudo docker compose restart api
    sudo docker compose restart nginx

### Логи

    # API (последние 100 строк)
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs --tail=100 api

    # Модель
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs --tail=100 model-server

    # Все сервисы в реальном времени
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml logs -f

### Обновление API

    cd /opt/nexus-ai
    sudo docker compose build --no-cache api
    sudo docker compose up -d api

### Миграция наборов обучения

Перед первым запуском API с поддержкой ручных Q/A-пар сделайте резервную копию
PostgreSQL и примените идемпотентную миграцию из репозитория. Команду запускает
оператор вручную на сервере; приложение не меняет схему БД при старте:

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml exec -T postgres \
      psql -U nexusai -d nexusai < /opt/nexus-ai/scripts/migrations/20261016_create_training_dataset_tables.sql

Для функций идемпотентного импорта и release-снимков (обновление от 17.10.2026)
примените следующие идемпотентные миграции в том же порядке:

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml exec -T postgres \
      psql -U nexusai -d nexusai < /opt/nexus-ai/scripts/migrations/20261017_training_dataset_batch_idempotency.sql
    sudo docker compose -f /opt/nexus-ai/docker-compose.yml exec -T postgres \
      psql -U nexusai -d nexusai < /opt/nexus-ai/scripts/migrations/20261017_create_training_dataset_releases.sql

Уже существующие партии с одинаковым `expert_key + source_sha256` в статусе
`pending_review`/`approved` мешают создать unique-индекс: в этом случае сначала
отклоните дубликаты через UI (или удалите лишние партии), затем повторите
применение миграции. Свежая база создаётся из `scripts/init-db.sql`, который
уже содержит оба объекта.

После успешной миграции пересоберите и запустите контейнер API по инструкции выше.

### Миграция реестра LoRA-адаптеров

Реестр версий адаптеров появился 18.10.2026. После резервной копии примените
идемпотентную миграцию в том же порядке:

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml exec -T postgres \
      psql -U nexusai -d nexusai < /opt/nexus-ai/scripts/migrations/20261018_create_lora_adapters.sql

Свежая база создаётся из `scripts/init-db.sql`, который уже содержит таблицу.
После миграции пересоберите контейнер API.

Контейнеру API нужен каталог для файлов адаптеров. По умолчанию код пишет в
`LORA_STORAGE_DIR` (дефолт `/app/data/loras`). В `docker-compose.yml` сервис
`api` уже монтирует этот каталог из хостового `./loras`:

    volumes:
      - ./loras:/app/data/loras
    environment:
      - LORA_STORAGE_DIR=/app/data/loras

Хранение контент-адресуемое (`{expert}/{sha256}.gguf`), поэтому одна копия файла
на все версии одного содержимого. Каталог `./loras` смонтирован и в
model-контейнер как `/loras`, поэтому загруженный через UI `.gguf` сразу виден
llama-серверу как `/loras/{expert}/{sha256}.gguf`.

### Публикация и применение адаптера (per-request LoRA)

Контур из двух частей:

1. **Файл адаптера** — загрузка через UI/API (`/admin/training/adapters` или
   `POST /v1/admin/training/adapters`) кладёт `.gguf` в `./loras`. Файл, которого
   нет в `./loras`, модели недоступен.
2. **Выбор адаптера на запрос** — при `LORA_RUNTIME_ENABLED=true` API для каждого
   эксперта с опубликованным (`status='published'`) адаптером добавляет в запрос
   к model-server поле `lora: [{"id": "<sha256>", "scale": 1.0}]`. Идентификатор —
   имя файла без `.gguf`. Если id неизвестен llama-server, адаптер не применяется
   (scale 0), ответ не ломается. Результат виден в SSE-событии `done`
   (`adapters_used`) и в аудите `chat.stream.*`.

Флаги сервиса `api` (по умолчанию **выключены**, поведение прежнее):

    environment:
      LORA_RUNTIME_ENABLED: "true"      # включает per-request подстановку lora
      LORA_APPLY_ON_PUBLISH: "false"    # дергать внешний перезагрузчик при publish
      MODEL_RELOAD_URL: ""              # URL перезагрузчика (если LORA_APPLY_ON_PUBLISH)

Перезагрузка model-сервера с `--lora` — шаг оператора. Скрипт читает реестр и
пересоздаёт только model-контейнер, добавляя `--lora` для каждого опубликованного
адаптера (генерирует `docker-compose.loras.yml` рядом с compose):

    bash /opt/nexus-ai/scripts/apply-loras.sh
    # без адаптеров — model стартует как раньше (--lora не добавляется)

Проверка: `docker logs nexus-model` (должно быть `loaded lora adapter` для каждого
файла), затем `GET /lora-adapters` и запрос с `lora: [{id, scale}]`.

Возврат к прежней модели: откатите публикацию в UI (реестр → `rolled_back`),
затем повторите `bash /opt/nexus-ai/scripts/apply-loras.sh` — скрипт сгенерирует
override без `--lora` для откаченного эксперта.

Автоматический перезапуск без оператора требует смонтированного docker.sock в API
(не делаем) либо внешнего перезагрузчика+`MODEL_RELOAD_URL`; `apply-loras.sh` —
основной путь.

### Изолированный CPU benchmark LoRA

Trainer не входит в рабочий `docker-compose.yml` и не запускается вместе с
сервисами автоматически. Описание отдельного ограниченного benchmark, ручного
переноса файлов и команд оператора находится в [`../training/README.md`](../training/README.md).
Benchmark использует только синтетический пример; он не запускает обучение на
утверждённых Q/A-партиях и не публикует адаптер.

Прогон от 18.10.2026 на боевом сервере: один LoRA-шаг (r=4, 36 токенов) занял
~12 минут при peak RAM 7.9 GiB. Практическое CPU-обучение на этом хосте
нежизнеспособно; полные результаты и проверка per-request LoRA-инференса — в
[`../training/SPIKE_RESULTS.md`](../training/SPIKE_RESULTS.md). Диагностический
адаптер из benchmark хранится в
`/opt/nexus-ai/training-runs/qwen3-cpu-smoke-01/adapter-qwen3-4b.gguf` и **не
должен** копироваться в `/opt/nexus-ai/loras` — он обучен на синтетике.

### Веб-поиск для сбора знаний

Сбор черновиков Q/A из интернета (`POST /v1/admin/training/research`) работает
через поискового провайдера. Провайдер задаётся переменной окружения
`SEARCH_PROVIDER` сервиса `api` в `/opt/nexus-ai/docker-compose.yml` (файл в
репозитории не хранится):

- `wikipedia` (по умолчанию) — бесплатный, **без ключа**. Поиск идёт по API
  Википедии: сначала на языке `WIKIPEDIA_LANG` (по умолчанию `ru`), затем
  результаты добираются из `en` до запрошенного количества; выдержки статей
  используются как сниппеты. Из коробки работает на текущем сервере, настройки
  не требуются.
- `brave` — Brave Search API, требует `BRAVE_SEARCH_API_KEY` (заголовок
  `X-Subscription-Token`). Бесплатного тарифа у Brave нет (только $5 кредитов
  в месяц), поэтому по умолчанию он не используется.

Отдельного ключа для `wikipedia` не нужно; для `brave` задайте секрет:

    environment:
      SEARCH_PROVIDER: brave             # по умолчанию wikipedia
      BRAVE_SEARCH_API_KEY: "<ключ>"     # только для brave

Ключи (если есть) живут только на сервере: поиск и вызов модели выполняются
внутри API, браузер запросы к провайдеру не делает. Если провайдер не настроен
(например, `brave` без ключа) или недоступен, эндпоинт отвечает `503`, а UI
показывает понятное сообщение. Значение таймаута поиска — `SEARCH_TIMEOUT`
(по умолчанию 20 с). После изменения переменных перезапустите контейнер API:

    sudo docker compose -f /opt/nexus-ai/docker-compose.yml up -d api

### Обучение адаптера на RunPod (GPU)

Полный QLoRA-прогон выполняется вне сервера, на GPU-поде RunPod; сам сервер
далее только принимает опубликованный GGUF-адаптер. Порядок:

1. Экспорт утверждённого release в JSONL:
   `python3 export_release.py --expert <expert> --out runs/<expert>/data --golden-size 40`
   (переменные `NEXUS_AI_URL`, `NEXUS_AI_ADMIN_KEY` — только server-side).
2. QLoRA-обучение (4-bit, PEFT + bitsandbytes):
   `python3 train_lora.py --config configs/<expert>.yaml --data runs/<expert>/data --out runs/<expert>/adapter`
3. Оценка (`format_compliance`, `similarity_avg`):
   `python3 evaluate.py --adapter runs/<expert>/adapter/adapter --data runs/<expert>/data --base-model Qwen/Qwen3-4B`
4. Конвертация PEFT→GGUF:
   `./convert_to_gguf.sh runs/<expert>/adapter/adapter runs/<expert>/<expert>-adapter.gguf`
5. Публикация: загрузить GGUF в реестр LoRA (UI `/admin/training/adapters`),
   затем при необходимости перезапустить model-server.

Базовый образ и шаблон запуска — `training/lora/Dockerfile` и
`training/lora/runpod.sh`; подробности — `training/lora/README.md`. Обучение
идёт только на утверждённых парах (loss по ответу ассистента,
`enable_thinking=false`), сплиты не разрывают похожие вопросы (Jaccard ≥ 0.75),
golden (30–50) — held-out для ручной проверки.

### Локальный UI и связка с сервером

Как поднять панель Laravel локально, проверить связку с боевым API и что
тестировать в UI — см. [`UI_TESTING.md`](UI_TESTING.md).

### Обновление Nginx-конфига

    # После правки /opt/nexus-ai/nginx/default.conf
    sudo docker compose restart nginx
    # Проверка синтаксиса:
    sudo docker exec nexus-nginx nginx -t

---

## Мониторинг

### RAM

    free -h
    sudo docker stats --no-stream

### Диск

    df -h /
    du -sh /opt/nexus-ai/models/
    du -sh /opt/nexus-ai/backups/
    du -sh /opt/nexus-ai/data/

### Процессы

    ps aux --sort=-%mem | head -10

### Модель — скорость

    sudo docker compose logs --tail=50 model-server | grep "t/s"

---

## Регламент обновлений

1. **За 24 часа до обновления** — сделать бэкап всех данных
2. **Уведомить пользователей** (если это прод)
3. **В день обновления:**
   - Остановить API: `sudo docker compose stop api`
   - Обновить код, Dockerfile
   - Пересобрать: `sudo docker compose build --no-cache api`
   - Запустить: `sudo docker compose up -d api`
   - Проверить smoke-тесты: `/opt/nexus-ai/scripts/smoke.sh`
   - Проверить system-health через API
4. **Откат при проблемах:**
   - Вернуть старый образ (если он есть)
   - Или восстановить из бэкапа

---

## Аварийные процедуры

### API не отвечает (502/504)

    # 1. Проверить статус контейнеров
    sudo docker compose ps

    # 2. Логи API
    sudo docker compose logs --tail=50 api

    # 3. Если Restarting — посмотреть ошибку
    sudo docker compose logs api | grep -i error

    # 4. Перезапуск
    sudo docker compose restart api

### Модель зависла

    # Проверка
    curl http://localhost:8080/health

    # Перезапуск модели
    sudo docker compose restart model-server

    # Если постоянно падает — проверить RAM
    free -h

### PostgreSQL не отвечает

    # Проверка
    sudo docker exec nexus-postgres pg_isready -U nexusai

    # Логи
    sudo docker compose logs --tail=50 postgres

### Qdrant не отвечает

    # Проверка
    curl -s http://nexus-qdrant:6333/readyz

    # Перезапуск
    sudo docker compose restart qdrant

---

## Ротация секретов

### API-ключ admin

    # Сгенерировать новый
    NEW_KEY=$(openssl rand -hex 32)

    # Обновить .env
    sed -i "s|^ADMIN_API_KEY=.*|ADMIN_API_KEY=$NEW_KEY|" /opt/nexus-ai/.env

    # Перезапустить API
    sudo docker compose up -d api

    # Сообщить команде новый ключ через защищённый канал

### Пароль PostgreSQL

    # Сложнее — нужно обновить и в .env, и в самой БД
    # Лучше делать в окно обслуживания
    # 1. ALTER USER nexusai WITH PASSWORD 'new_pass';
    # 2. Обновить .env
    # 3. Перезапустить API

---

| KB @CerberRus00 - Nexus Invest Team
