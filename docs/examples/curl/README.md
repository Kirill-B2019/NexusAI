# NEXUS AI — Примеры cURL

Все основные запросы через curl.

## Переменные

    export NEXUS_URL="http://31.128.38.96/api"
    export ADMIN_KEY="<ваш admin-ключ>"
    export PROJECT_ID="<uuid проекта>"
    export PROJECT_KEY="<project-ключ>"

## Healthcheck

    curl -s $NEXUS_URL/health

## Version

    curl -s $NEXUS_URL/version | jq

## Эксперты

Список активных:

    curl -s -H "X-API-Key: $ADMIN_KEY" $NEXUS_URL/v1/experts | jq

Все (включая отключённых):

    curl -s -H "X-API-Key: $ADMIN_KEY" $NEXUS_URL/v1/experts/all | jq

## Проекты

Список:

    curl -s -H "X-API-Key: $ADMIN_KEY" $NEXUS_URL/v1/projects | jq

Создать:

    curl -s -X POST $NEXUS_URL/v1/projects \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"name": "Мой проект", "external_id": "laravel-001"}' | jq

## API-ключи проекта

Выдать:

    curl -s -X POST $NEXUS_URL/v1/projects/$PROJECT_ID/keys \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"name": "Laravel", "rate_limit_per_min": 120}' | jq

Отозвать:

    curl -s -X DELETE $NEXUS_URL/v1/projects/$PROJECT_ID/keys/$KEY_ID \
      -H "X-API-Key: $ADMIN_KEY"

## Загрузка документов

    curl -X POST $NEXUS_URL/v1/projects/$PROJECT_ID/documents \
      -H "X-API-Key: $ADMIN_KEY" \
      -F "file=@/path/to/document.pdf" | jq

## Чат — single

    curl -s -X POST $NEXUS_URL/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Что такое API Gateway?",
        "expert": "system_architect",
        "orchestrate": false,
        "use_rag": false
      }' | jq '.content'

## Чат — оркестрация

    curl -s -X POST $NEXUS_URL/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Оцени проект платёжной системы",
        "experts": ["fintech", "digital_law", "project_scoring"],
        "use_rag": false
      }' | jq '{mode, experts_used, elapsed_s}'

## Чат — с RAG

    curl -s -X POST $NEXUS_URL/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Какой бюджет в документе?",
        "expert": "project_scoring",
        "orchestrate": false,
        "project_id": "'$PROJECT_ID'",
        "use_rag": true,
        "rag_top_k": 4
      }' | jq '{rag_used, sources: (.sources | length), content}'

## SSE-стрим

    curl -N -s -X POST $NEXUS_URL/v1/chat/stream \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"message": "Что такое монолит?", "expert": "system_architect"}'

Флаг -N отключает буферизацию.

## Диалоги

Создать:

    curl -s -X POST $NEXUS_URL/v1/projects/$PROJECT_ID/conversations \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"title": "Обсуждение архитектуры"}' | jq

Отправить сообщение с сохранением:

    curl -s -X POST $NEXUS_URL/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Что такое монолит?",
        "expert": "system_architect",
        "project_id": "'$PROJECT_ID'",
        "conversation_id": "'$CONV_ID'",
        "save_to_conversation": true
      }' | jq '.message_ids'

История с пагинацией:

    curl -s -H "X-API-Key: $ADMIN_KEY" \
      "$NEXUS_URL/v1/conversations/$CONV_ID/messages?limit=50" | jq

## Решения

    curl -s -X POST $NEXUS_URL/v1/projects/$PROJECT_ID/decisions \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "title": "Использовать модульный монолит",
        "content": "Обоснование...",
        "status": "active"
      }' | jq

## Задачи

    curl -s -X POST $NEXUS_URL/v1/projects/$PROJECT_ID/tasks \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "title": "Написать API Gateway",
        "priority": "high",
        "assignee_expert": "software_engineer",
        "due_date": "2026-12-01"
      }' | jq

## Админ

Stats:

    curl -s -H "X-API-Key: $ADMIN_KEY" $NEXUS_URL/v1/admin/stats | jq

System health:

    curl -s -H "X-API-Key: $ADMIN_KEY" $NEXUS_URL/v1/admin/system-health | jq

Audit:

    curl -s -H "X-API-Key: $ADMIN_KEY" "$NEXUS_URL/v1/admin/audit?limit=10" | jq

## Метрики Prometheus

    curl -s $NEXUS_URL/metrics | head -20

---

| KB @CerberRus00 - Nexus Invest Team
