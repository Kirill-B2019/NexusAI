#!/bin/bash
# Regression test NEXUS AI
# Прогоняет фиксированный набор вопросов, проверяет ключевые слова, пишет отчёт.
set -uo pipefail

BASE="${BASE_URL:-http://localhost/api}"
ADMIN_KEY="${ADMIN_KEY:-$(grep '^ADMIN_API_KEY=' /opt/nexus-ai/.env | cut -d= -f2-)}"
TESTS_FILE="${TESTS_FILE:-/opt/nexus-ai/tests/regression.json}"
REPORT_DIR="${REPORT_DIR:-/opt/nexus-ai/tests/reports}"
TIMEOUT="${TIMEOUT:-600}"

mkdir -p "$REPORT_DIR"
TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
REPORT="$REPORT_DIR/regression_$TIMESTAMP.json"
LOG="$REPORT_DIR/regression_$TIMESTAMP.log"

green() { echo -e "\033[32m$1\033[0m"; }
red() { echo -e "\033[31m$1\033[0m"; }
yellow() { echo -e "\033[33m$1\033[0m"; }

TOTAL=$(jq '.cases | length' "$TESTS_FILE")
PASS=0
FAIL=0

echo "═══════════════════════════════════════════════" | tee "$LOG"
echo "  NEXUS AI Regression Test" | tee -a "$LOG"
echo "  Cases: $TOTAL" | tee -a "$LOG"
echo "  Started: $TIMESTAMP" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"

RESULT_JSON="[]"
START_ALL=$(date +%s)

for i in $(seq 0 $((TOTAL-1))); do
    CASE_ID=$(jq -r ".cases[$i].id" "$TESTS_FILE")
    EXPERT=$(jq -r ".cases[$i].expert // empty" "$TESTS_FILE")
    MESSAGE=$(jq -r ".cases[$i].message" "$TESTS_FILE")
    MODE=$(jq -r ".cases[$i].mode // \"single\"" "$TESTS_FILE")
    MAX_ELAPSED=$(jq -r ".cases[$i].max_elapsed_s" "$TESTS_FILE")

    echo "" | tee -a "$LOG"
    yellow "[$((i+1))/$TOTAL] $CASE_ID" | tee -a "$LOG"
    echo "  Q: $MESSAGE" | tee -a "$LOG"

    # Формируем тело запроса
    if [ "$MODE" = "orchestration" ]; then
        BODY=$(jq -n --arg msg "$MESSAGE" '{message: $msg, orchestrate: true, use_rag: false}')
    else
        BODY=$(jq -n --arg msg "$MESSAGE" --arg exp "$EXPERT" \
            '{message: $msg, expert: $exp, orchestrate: false, use_rag: false}')
    fi

    # Вызов
    START_CASE=$(date +%s)
    RESPONSE=$(curl -s -m "$TIMEOUT" -X POST "$BASE/v1/chat" \
        -H "X-API-Key: $ADMIN_KEY" \
        -H "Content-Type: application/json" \
        -d "$BODY")
    END_CASE=$(date +%s)
    ELAPSED=$((END_CASE - START_CASE))

    # Извлекаем контент
    if [ "$MODE" = "orchestration" ]; then
        CONTENT=$(echo "$RESPONSE" | jq -r '.aggregated // ""')
    else
        CONTENT=$(echo "$RESPONSE" | jq -r '.content // ""')
    fi

    if [ -z "$CONTENT" ] || [ "$CONTENT" = "null" ]; then
        red "  ✗ Пустой ответ" | tee -a "$LOG"
        FAIL=$((FAIL+1))
        RESULT_JSON=$(echo "$RESULT_JSON" | jq \
            --arg id "$CASE_ID" --argjson elapsed "$ELAPSED" \
            '. + [{id: $id, status: "fail", reason: "empty_response", elapsed_s: $elapsed}]')
        continue
    fi

    # Проверка времени
    if [ "$ELAPSED" -gt "$MAX_ELAPSED" ]; then
        red "  ✗ Таймаут: ${ELAPSED}s > ${MAX_ELAPSED}s" | tee -a "$LOG"
        # Не считаем fail, но отмечаем
    fi

    # Проверка ключевых слов
    CONTENT_LOWER=$(echo "$CONTENT" | tr '[:upper:]' '[:lower:]')
    MATCHED=0
    MATCHED_KEYWORDS=""
    for kw_idx in $(seq 0 $(( $(jq ".cases[$i].expected_keywords | length" "$TESTS_FILE") - 1 ))); do
        KW=$(jq -r ".cases[$i].expected_keywords[$kw_idx]" "$TESTS_FILE")
        KW_LOWER=$(echo "$KW" | tr '[:upper:]' '[:lower:]')
        if echo "$CONTENT_LOWER" | grep -q "$KW_LOWER"; then
            MATCHED=$((MATCHED+1))
            MATCHED_KEYWORDS="$MATCHED_KEYWORDS $KW"
        fi
    done

    KW_TOTAL=$(jq ".cases[$i].expected_keywords | length" "$TESTS_FILE")
    KW_RATIO=$(awk "BEGIN {printf \"%.2f\", $MATCHED/$KW_TOTAL}")

    # Порог: ≥50% ключевых слов — PASS
    if [ "$(awk "BEGIN {print ($KW_RATIO >= 0.5)}")" = "1" ]; then
        green "  ✓ PASS: $MATCHED/$KW_TOTAL keywords, ${ELAPSED}s" | tee -a "$LOG"
        PASS=$((PASS+1))
        RESULT_JSON=$(echo "$RESULT_JSON" | jq \
            --arg id "$CASE_ID" --argjson matched "$MATCHED" --argjson total "$KW_TOTAL" \
            --argjson elapsed "$ELAPSED" --arg kws "$MATCHED_KEYWORDS" \
            '. + [{id: $id, status: "pass", matched: $matched, total: $total, elapsed_s: $elapsed, keywords: $kws}]')
    else
        red "  ✗ FAIL: $MATCHED/$KW_TOTAL keywords ($KW_RATIO), ${ELAPSED}s" | tee -a "$LOG"
        echo "    Matched:$MATCHED_KEYWORDS" | tee -a "$LOG"
        FAIL=$((FAIL+1))
        RESULT_JSON=$(echo "$RESULT_JSON" | jq \
            --arg id "$CASE_ID" --argjson matched "$MATCHED" --argjson total "$KW_TOTAL" \
            --argjson elapsed "$ELAPSED" --arg kws "$MATCHED_KEYWORDS" \
            --arg preview "$(echo "$CONTENT" | head -c 200)" \
            '. + [{id: $id, status: "fail", matched: $matched, total: $total, elapsed_s: $elapsed, keywords: $kws, preview: $preview}]')
    fi

    sleep 1
done

END_ALL=$(date +%s)
TOTAL_ELAPSED=$((END_ALL - START_ALL))

# Сохраняем отчёт
echo "$RESULT_JSON" | jq --arg ts "$TIMESTAMP" --argjson pass "$PASS" --argjson fail "$FAIL" \
    --argjson total "$TOTAL" --argjson elapsed "$TOTAL_ELAPSED" \
    '{
        timestamp: $ts,
        summary: { total: $total, pass: $pass, fail: $fail, elapsed_s: $elapsed },
        results: .
    }' > "$REPORT"

echo "" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"
if [ "$FAIL" -eq 0 ]; then
    green "  ВСЁ ОК: $PASS/$TOTAL прошло за ${TOTAL_ELAPSED}s" | tee -a "$LOG"
else
    red "  ИТОГ: $PASS/$TOTAL прошло, $FAIL упало за ${TOTAL_ELAPSED}s" | tee -a "$LOG"
fi
echo "  Отчёт: $REPORT" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"

# Показываем сводку
jq '.summary' "$REPORT"
