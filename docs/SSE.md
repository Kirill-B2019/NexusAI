# NEXUS AI — Server-Sent Events (SSE)

## Что это

SSE-стрим позволяет получать токены ответа по мере генерации,
а не ждать полный ответ. UX становится как в ChatGPT.

## Эндпоинт

    POST /api/v1/chat/stream

Аутентификация: X-API-Key или Authorization: Bearer.

## Формат запроса

    {
      "message": "обязательно",
      "expert": "system_architect",         // single
      "experts": ["fintech", "digital_law"], // manual list
      "thinking": false,
      "project_id": "uuid",
      "use_rag": true,
      "rag_top_k": 4,
      "rag_min_score": 0.5,
      "document_ids": ["uuid"]
    }

## Формат ответа (SSE)

Каждое событие:

    event: <тип>
    data: <JSON>
    <пустая строка>

## Типы событий

### start
Первое событие. Метаданные запроса.

    event: start
    data: {
      "mode": "single" | "auto_orchestration" | "manual_orchestration",
      "experts_used": ["fintech", ...],
      "experts_skipped": [{"key": "digital_law", "reason": "disabled"}],
      "route_method": "explicit" | "keyword" | "llm" | "manual",
      "rag_used": true,
      "sources": [{"index":1, "document_name": "...", "chunk_index":5, "score":0.8}]
    }

### expert_start
Начало ответа конкретного эксперта.

    event: expert_start
    data: {"expert": "fintech"}

### reasoning
Токен внутреннего размышления (только при thinking=true).

    event: reasoning
    data: {"expert": "fintech", "delta": "Надо проверить..."}

### token
Токен финального ответа.

    event: token
    data: {"expert": "fintech", "delta": "Бюджет"}

### expert_done
Эксперт завершил ответ.

    event: expert_done
    data: {"expert": "fintech", "content_length": 1500}

### expert_error
Ошибка у конкретного эксперта (не роняет остальных).

    event: expert_error
    data: {"expert": "fintech", "error": "timeout"}

### done
Финальное событие с агрегированным ответом.

    event: done
    data: {
      "mode": "auto_orchestration",
      "experts_used": ["fintech", "digital_law"],
      "aggregated": "## Совет экспертов NEXUS AI\n\n### 💰 ...",
      "sources": [...],
      "elapsed_s": 245.6,
      "summary": {"total": 2, "ok": 2, "failed": 0}
    }

### error
Фатальная ошибка.

    event: error
    data: {"error": "all_experts_failed"}

## Пример curl

    curl -N -s -X POST http://31.128.38.96/api/v1/chat/stream \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Что такое API Gateway?",
        "expert": "system_architect",
        "use_rag": false
      }'

Флаг -N отключает буферизацию curl.

## Пример JavaScript (браузер)

    const response = await fetch('/api/v1/chat/stream', {
      method: 'POST',
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ message, expert })
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      // Разбор SSE: события разделены \n\n
      const events = buffer.split('\n\n');
      buffer = events.pop() || '';

      for (const ev of events) {
        const lines = ev.split('\n');
        let eventType = 'message';
        let dataLine = '';
        for (const line of lines) {
          if (line.startsWith('event: ')) eventType = line.slice(7).trim();
          else if (line.startsWith('data: ')) dataLine = line.slice(6);
        }
        if (!dataLine) continue;
        const payload = JSON.parse(dataLine);

        if (eventType === 'token') {
          // payload.delta — токен для добавления к ответу
          appendToOutput(payload.expert, payload.delta);
        } else if (eventType === 'expert_start') {
          startExpertBlock(payload.expert);
        } else if (eventType === 'done') {
          showFinal(payload.aggregated, payload.sources);
        }
      }
    }

## Пример TypeScript (Nest.js)

    import { Injectable } from '@nestjs/common';
    import { Observable } from 'rxjs';

    @Injectable()
    export class NexusStreamService {
      streamChat(dto: ChatDto): Observable<any> {
        return new Observable(observer => {
          fetch(`${this.baseUrl}/v1/chat/stream`, {
            method: 'POST',
            headers: { 'X-API-Key': this.apiKey, 'Content-Type': 'application/json' },
            body: JSON.stringify(dto),
          }).then(async response => {
            const reader = response.body!.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            while (true) {
              const { done, value } = await reader.read();
              if (done) { observer.complete(); break; }
              buffer += decoder.decode(value, { stream: true });
              const events = buffer.split('\n\n');
              buffer = events.pop() || '';
              for (const ev of events) {
                const eventType = (ev.match(/^event: (.+)$/m) || [])[1] || 'message';
                const dataLine = (ev.match(/^data: (.+)$/m) || [])[1];
                if (!dataLine) continue;
                observer.next({ type: eventType, data: JSON.parse(dataLine) });
              }
            }
          }).catch(err => observer.error(err));
        });
      }
    }

## Особенности

### Nginx
Требуется отключить буферизацию:

    proxy_buffering off;
    proxy_cache off;

Наш конфиг уже настроен.

### Долгие ответы
При thinking=true на CPU стрим может идти 3–5 минут.
Токены идут постепенно — пользователь видит прогресс.

### Мультиэкспертная оркестрация
При 3+ экспертах стрим идёт последовательно:
1. expert_start (эксперт A)
2. tokens A
3. expert_done A
4. expert_start (эксперт B)
5. tokens B
...
N. done (финал)

Все эксперты получают один RAG-контекст.

### Отмена
Клиент может закрыть соединение — сервер прекратит генерацию (CancelledError).

### Аудит
Каждый стрим-запрос пишется в audit_log с action = chat.stream.<mode>.
