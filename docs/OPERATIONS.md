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
