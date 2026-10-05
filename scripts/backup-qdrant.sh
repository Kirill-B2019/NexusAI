#!/bin/bash
# Бэкап Qdrant NEXUS AI (snapshots всех коллекций)
set -euo pipefail

BACKUP_DIR="/opt/nexus-ai/backups/qdrant"
RETENTION_DAYS=30
LOG="/opt/nexus-ai/backups/backup.log"
QDRANT_URL="http://localhost:6333"

# Qdrant доступен только внутри Docker-сети, поэтому используем временный контейнер
QDRANT_INTERNAL="http://nexus-qdrant:6333"
DOCKER_NET="nexus-ai_default"

mkdir -p "$BACKUP_DIR"
DATE=$(date +%Y-%m-%d_%H-%M-%S)

echo "[$(date -Iseconds)] Начало бэкапа Qdrant" >> "$LOG"

# Получаем список коллекций
COLLECTIONS=$(docker run --rm --network "$DOCKER_NET" curlimages/curl -s "$QDRANT_INTERNAL/collections" \
    | grep -o '"name":"[^"]*"' | sed 's/"name":"//;s/"//' || echo "")

if [ -z "$COLLECTIONS" ]; then
    echo "[$(date -Iseconds)] Коллекций нет — нечего бэкапить" >> "$LOG"
    exit 0
fi

echo "[$(date -Iseconds)] Коллекции: $COLLECTIONS" >> "$LOG"

for COL in $COLLECTIONS; do
    SNAP_NAME="nexusai_${COL}_${DATE}.snapshot"
    echo "[$(date -Iseconds)] Создание снапшота $COL..." >> "$LOG"
    
    # Запускаем создание снапшота
    docker run --rm --network "$DOCKER_NET" curlimages/curl -s -X POST \
        "$QDRANT_INTERNAL/collections/$COL/snapshots" > /dev/null
    
    # Скачиваем последний снапшот
    SNAP_FILE=$(docker run --rm --network "$DOCKER_NET" curlimages/curl -s \
        "$QDRANT_INTERNAL/collections/$COL/snapshots" \
        | grep -o '"name":"[^"]*"' | tail -1 | sed 's/"name":"//;s/"//')
    
    if [ -n "$SNAP_FILE" ]; then
        docker run --rm --network "$DOCKER_NET" curlimages/curl -s \
            "$QDRANT_INTERNAL/collections/$COL/snapshots/$SNAP_FILE" \
            > "$BACKUP_DIR/$SNAP_NAME"
        SIZE=$(du -h "$BACKUP_DIR/$SNAP_NAME" | cut -f1)
        echo "[$(date -Iseconds)] OK: $SNAP_NAME ($SIZE)" >> "$LOG"
    else
        echo "[$(date -Iseconds)] ОШИБКА: не удалось получить снапшот $COL" >> "$LOG"
    fi
done

find "$BACKUP_DIR" -name "nexusai_*.snapshot" -mtime +$RETENTION_DAYS -delete
echo "[$(date -Iseconds)] Очистка старше $RETENTION_DAYS дней выполнена" >> "$LOG"
