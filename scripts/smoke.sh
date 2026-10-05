#!/bin/bash
# Smoke-тесты NEXUS AI: быстрый прогон ключевых эндпоинтов
set -uo pipefail

BASE="${BASE_URL:-http://localhost/api}"
ADMIN_KEY="${ADMIN_KEY:-$(grep '^ADMIN_API_KEY=' /opt/nexus-ai/.env | cut -d= -f2-)}"

PASS=0
FAIL=0
FAILED_TESTS=()

green() { echo -e "\033[32m$1\033[0m"; }
red() { echo -e "\033[31m$1\033[0m"; }
yellow() { echo -e "\033[33m$1\033[0m"; }

check() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" = "$expected" ]; then
        green "  ✓ $name ($actual)"
        PASS=$((PASS+1))
    else
        red "  ✗ $name (expected $expected, got $actual)"
        FAIL=$((FAIL+1))
        FAILED_TESTS+=("$name")
    fi
}

http_code() {
    curl -s -o /dev/null -w "%{http_code}" "$@"
}

echo "═══════════════════════════════════════════════"
echo "  NEXUS AI Smoke Tests"
echo "  BASE=$BASE"
echo "═══════════════════════════════════════════════"
echo ""

# ─── 1. Публичные эндпоинты ────────────────────────
yellow "[1] Публичные"
check "GET /health" 200 "$(http_code $BASE/health)"
check "GET /version" 200 "$(http_code $BASE/version)"

# ─── 2. Аутентификация ─────────────────────────────
yellow "[2] Аутентификация"
check "GET /v1/experts без ключа → 401" 401 "$(http_code $BASE/v1/experts)"
check "GET /v1/experts с admin-ключом → 200" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/v1/experts)"

# ─── 3. Эксперты ───────────────────────────────────
yellow "[3] Эксперты"
COUNT=$(curl -s -H "X-API-Key: $ADMIN_KEY" $BASE/v1/experts | jq '.experts | length')
if [ "$COUNT" -ge 6 ]; then
    green "  ✓ Экспертов в списке: $COUNT (≥6)"
    PASS=$((PASS+1))
else
    red "  ✗ Экспертов в списке: $COUNT (<6)"
    FAIL=$((FAIL+1))
    FAILED_TESTS+=("experts_count")
fi

# ─── 4. Проекты ────────────────────────────────────
yellow "[4] Проекты"
check "GET /v1/projects" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/v1/projects)"

# ─── 5. Docs защита ────────────────────────────────
yellow "[5] OpenAPI / Swagger"
check "GET /docs без ключа → 401" 401 "$(http_code $BASE/docs)"
check "GET /docs с admin → 200" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/docs)"
check "GET /openapi.json с admin → 200" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/openapi.json)"

# ─── 6. Админ ──────────────────────────────────────
yellow "[6] Админ-эндпоинты"
check "GET /v1/admin/stats" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/v1/admin/stats)"
check "GET /v1/admin/system-health" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/v1/admin/system-health)"
check "GET /v1/admin/audit" 200 "$(http_code -H "X-API-Key: $ADMIN_KEY" $BASE/v1/admin/audit)"

# ─── 7. Проверка system health ─────────────────────
yellow "[7] System health (все сервисы)"
HEALTH=$(curl -s -H "X-API-Key: $ADMIN_KEY" $BASE/v1/admin/system-health)
STATUS=$(echo "$HEALTH" | jq -r '.status')
check "system-health status" "ok" "$STATUS"

for svc in api model embeddings postgres qdrant; do
    s=$(echo "$HEALTH" | jq -r ".services.\"$svc\".status")
    check "  service $svc" "ok" "$s"
done

# ─── 8. Rate limit заголовки ───────────────────────
yellow "[8] Rate limit"
LIMIT=$(curl -s -i -H "X-API-Key: $ADMIN_KEY" $BASE/v1/experts 2>&1 | grep -i "x-ratelimit-limit" | tr -d '\r' | awk '{print $2}')
if [ -n "$LIMIT" ]; then
    green "  ✓ X-RateLimit-Limit: $LIMIT"
    PASS=$((PASS+1))
else
    red "  ✗ X-RateLimit-Limit отсутствует"
    FAIL=$((FAIL+1))
    FAILED_TESTS+=("rate_limit_header")
fi

# ─── Итог ──────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════"
if [ $FAIL -eq 0 ]; then
    green "  ВСЁ ОК: $PASS прошло, 0 упало"
    echo "═══════════════════════════════════════════════"
    exit 0
else
    red "  ПРОБЛЕМЫ: $PASS прошло, $FAIL упало"
    echo ""
    red "  Упавшие тесты:"
    for t in "${FAILED_TESTS[@]}"; do
        echo "    - $t"
    done
    echo "═══════════════════════════════════════════════"
    exit 1
fi
