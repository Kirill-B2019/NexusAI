#!/bin/bash
# Healthcheck NEXUS AI
set -uo pipefail

LOG="/opt/nexus-ai/backups/healthcheck.log"
DATE=$(date -Iseconds)
STATUS="OK"
DETAILS=""

# 1. Контейнеры
CONTAINERS=$(docker compose -f /opt/nexus-ai/docker-compose.yml ps --format json 2>/dev/null)
DOWN=$(echo "$CONTAINERS" | grep -c '"State":"running"' || echo 0)
TOTAL=$(echo "$CONTAINERS" | grep -c '"Service"' || echo 0)

if [ "$DOWN" -ne "$TOTAL" ]; then
    STATUS="WARN"
    DETAILS="$DETAILS containers=$DOWN/$TOTAL"
fi

# 2. API /health
API=$(docker run --rm --network nexus-ai_default curlimages/curl -s -m 10 http://nexus-api:8000/health 2>/dev/null || echo "FAIL")
if ! echo "$API" | grep -q '"status":"ok"'; then
    STATUS="FAIL"
    DETAILS="$DETAILS api=FAIL"
fi

# 3. Модель /health
MODEL=$(docker run --rm --network nexus-ai_default curlimages/curl -s -m 10 http://nexus-model:8080/health 2>/dev/null || echo "FAIL")
if ! echo "$MODEL" | grep -q '"status":"ok"'; then
    STATUS="FAIL"
    DETAILS="$DETAILS model=FAIL"
fi

# 4. PostgreSQL
if ! docker exec nexus-postgres pg_isready -U nexusai -d nexusai > /dev/null 2>&1; then
    STATUS="FAIL"
    DETAILS="$DETAILS postgres=FAIL"
fi

# 5. Qdrant
QDRANT=$(docker run --rm --network nexus-ai_default curlimages/curl -s -m 10 http://nexus-qdrant:6333/readyz 2>/dev/null || echo "FAIL")
if ! echo "$QDRANT" | grep -q "all shards are ready"; then
    STATUS="FAIL"
    DETAILS="$DETAILS qdrant=FAIL"
fi

# 6. RAM и диск
RAM=$(free -m | awk 'NR==2 {printf "%.1f%%", $3*100/$2}')
DISK=$(df / | awk 'NR==2 {print $5}')

echo "[$DATE] STATUS=$STATUS RAM=$RAM DISK=$DISK$DETAILS" >> "$LOG"

# Оставляем только последние 1000 строк
tail -1000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"

exit 0
