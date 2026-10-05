#!/bin/bash
# Бэкап конфигурации NEXUS AI
set -euo pipefail

BACKUP_DIR="/opt/nexus-ai/backups/config"
RETENTION_DAYS=30
DATE=$(date +%Y-%m-%d_%H-%M-%S)
FILE="$BACKUP_DIR/nexusai_config_$DATE.tar.gz"
LOG="/opt/nexus-ai/backups/backup.log"

mkdir -p "$BACKUP_DIR"
echo "[$(date -Iseconds)] Начало бэкапа конфигурации" >> "$LOG"

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# Копируем файлы во временную директорию
cp /opt/nexus-ai/docker-compose.yml "$TMPDIR/" 2>/dev/null || true
cp /opt/nexus-ai/.env "$TMPDIR/" 2>/dev/null || true
cp -r /opt/nexus-ai/api "$TMPDIR/" 2>/dev/null || true
cp -r /opt/nexus-ai/nginx "$TMPDIR/" 2>/dev/null || true
cp -r /opt/nexus-ai/scripts "$TMPDIR/" 2>/dev/null || true
mkdir -p "$TMPDIR/systemd"
cp /etc/systemd/system/nexus-ai.service "$TMPDIR/systemd/" 2>/dev/null || true

# Упаковываем
tar -czf "$FILE" -C "$TMPDIR" .

if [ -f "$FILE" ]; then
    SIZE=$(du -h "$FILE" | cut -f1)
    echo "[$(date -Iseconds)] OK: $FILE ($SIZE)" >> "$LOG"
else
    echo "[$(date -Iseconds)] ОШИБКА бэкапа конфигурации" >> "$LOG"
    exit 1
fi

find "$BACKUP_DIR" -name "nexusai_config_*.tar.gz" -mtime +$RETENTION_DAYS -delete
echo "[$(date -Iseconds)] Очистка старше $RETENTION_DAYS дней выполнена" >> "$LOG"
