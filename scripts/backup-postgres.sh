#!/bin/bash
# Бэкап PostgreSQL NEXUS AI
set -euo pipefail

BACKUP_DIR="/opt/nexus-ai/backups/postgres"
RETENTION_DAYS=14
DATE=$(date +%Y-%m-%d_%H-%M-%S)
FILE="$BACKUP_DIR/nexusai_$DATE.sql.gz"
LOG="/opt/nexus-ai/backups/backup.log"

mkdir -p "$BACKUP_DIR"

echo "[$(date -Iseconds)] Начало бэкапа PostgreSQL" >> "$LOG"

if docker exec nexus-postgres pg_dump -U nexusai -d nexusai | gzip > "$FILE"; then
    SIZE=$(du -h "$FILE" | cut -f1)
    echo "[$(date -Iseconds)] OK: $FILE ($SIZE)" >> "$LOG"
else
    echo "[$(date -Iseconds)] ОШИБКА бэкапа PostgreSQL" >> "$LOG"
    exit 1
fi

find "$BACKUP_DIR" -name "nexusai_*.sql.gz" -mtime +$RETENTION_DAYS -delete
echo "[$(date -Iseconds)] Очистка старше $RETENTION_DAYS дней выполнена" >> "$LOG"
