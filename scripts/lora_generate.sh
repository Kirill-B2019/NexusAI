#!/bin/bash
# Генерация синтетических обучающих данных для LoRA
set -uo pipefail

BASE="${BASE_URL:-http://localhost/api}"
ADMIN_KEY="${ADMIN_KEY:-$(grep '^ADMIN_API_KEY=' /opt/nexus-ai/.env | cut -d= -f2-)}"

LORA_DIR="/opt/nexus-ai/lora"
PROMPTS_DIR="$LORA_DIR/prompts"
SYNTHETIC_DIR="$LORA_DIR/sources/synthetic"

mkdir -p "$SYNTHETIC_DIR"

EXPERT="${1:-}"
if [ -z "$EXPERT" ]; then
    echo "Использование: $0 <expert_key>"
    exit 1
fi

PROMPTS_FILE="$PROMPTS_DIR/$EXPERT.json"
if [ ! -f "$PROMPTS_FILE" ]; then
    echo "ОШИБКА: $PROMPTS_FILE не найден"
    exit 1
fi

OUTPUT="$SYNTHETIC_DIR/$EXPERT.jsonl"
LOG="$SYNTHETIC_DIR/${EXPERT}.log"

> "$OUTPUT"

TOTAL=$(jq '.questions | length' "$PROMPTS_FILE")
echo "═══════════════════════════════════════════════" | tee "$LOG"
echo "  Генерация датасета: $EXPERT" | tee -a "$LOG"
echo "  Вопросов: $TOTAL" | tee -a "$LOG"
echo "  Output: $OUTPUT" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"

# Получаем system_prompt
SYSTEM_PROMPT=$(curl -s -H "X-API-Key: $ADMIN_KEY" \
    "$BASE/v1/experts/$EXPERT" | jq -r '.system_prompt // empty')

if [ -z "$SYSTEM_PROMPT" ]; then
    echo "ОШИБКА: не удалось получить system_prompt для $EXPERT" | tee -a "$LOG"
    exit 1
fi

echo "✓ system_prompt: ${#SYSTEM_PROMPT} символов" | tee -a "$LOG"

PASS=0
FAIL=0

for i in $(seq 0 $((TOTAL-1))); do
    QUESTION=$(jq -r ".questions[$i]" "$PROMPTS_FILE")

    echo "" | tee -a "$LOG"
    echo "[$((i+1))/$TOTAL] $QUESTION" | tee -a "$LOG"

    BODY=$(jq -n --arg msg "$QUESTION" --arg exp "$EXPERT" \
        '{message: $msg, expert: $exp, orchestrate: false, use_rag: false, thinking: false}')

    RESPONSE=$(curl -s -m 300 -X POST "$BASE/v1/chat" \
        -H "X-API-Key: $ADMIN_KEY" \
        -H "Content-Type: application/json" \
        -d "$BODY")

    ANSWER=$(echo "$RESPONSE" | jq -r '.content // empty')
    ELAPSED=$(echo "$RESPONSE" | jq -r '.elapsed_s // 0')

    if [ -z "$ANSWER" ] || [ "$ANSWER" = "null" ]; then
        echo "  ✗ Пустой ответ" | tee -a "$LOG"
        FAIL=$((FAIL+1))
        continue
    fi

    LEN=${#ANSWER}
    if [ "$LEN" -lt 50 ]; then
        echo "  ✗ Слишком короткий ($LEN символов)" | tee -a "$LOG"
        FAIL=$((FAIL+1))
        continue
    fi

    # Сохраняем в JSONL
    echo "$RESPONSE" | jq -c \
        --arg sys "$SYSTEM_PROMPT" \
        --arg user "$QUESTION" \
        --arg expert "$EXPERT" \
        '{
            messages: [
                {role: "system", content: $sys},
                {role: "user", content: $user},
                {role: "assistant", content: .content}
            ],
            metadata: {
                expert: $expert,
                elapsed_s: .elapsed_s,
                source: "synthetic"
            }
        }' >> "$OUTPUT"

    echo "  ✓ Сохранено ($LEN символов, ${ELAPSED}s)" | tee -a "$LOG"
    PASS=$((PASS+1))

    sleep 1
done

echo "" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"
echo "  Готово: $PASS/$TOTAL прошло, $FAIL упало" | tee -a "$LOG"
echo "  Размер: $(wc -l < "$OUTPUT") строк" | tee -a "$LOG"
echo "═══════════════════════════════════════════════" | tee -a "$LOG"
