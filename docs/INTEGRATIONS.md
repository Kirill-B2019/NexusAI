# NEXUS AI — Интеграции

Инструкции для подключения сторонних систем к API NEXUS AI.

## Аутентификация

Все запросы к /v1/* требуют API-ключ в заголовке:

    X-API-Key: <key>

или

    Authorization: Bearer <key>

### Типы ключей

| Тип | Где получить | Права |
|-----|--------------|-------|
| Admin | В .env на сервере NEXUS AI | Полный доступ ко всему |
| Project | POST /v1/projects/{id}/keys | Работа в рамках своего проекта |

Project-ключ создаётся через admin-эндпоинт:

    curl -X POST http://31.128.38.96/api/v1/projects/{project_id}/keys \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "name": "Laravel production",
        "allowed_experts": null,
        "rate_limit_per_min": 120
      }'

Ответ содержит поле "key" — это plaintext, показывается один раз. Сохраните его.

### allowed_experts

Если указан — project-ключ видит только этих экспертов:

    "allowed_experts": ["system_architect", "fintech"]

Если null или отсутствует — доступны все эксперты проекта.

## Базовый URL

    http://31.128.38.96/api

При переходе на HTTPS — замените на https://api.nexus.local или ваш домен.

## Формат ошибок

    {
      "detail": "текст ошибки"
    }

Коды:
- 400 — неверный запрос (валидация)
- 401 — нет/невалидный API-ключ
- 403 — нет доступа (например, чужой проект или admin-only)
- 404 — ресурс не найден
- 409 — конфликт (дубликат)
- 413 — файл слишком большой
- 429 — превышен rate limit
- 500 — внутренняя ошибка
- 502 — ошибка модели
- 504 — таймаут модели

## Рекомендации по клиенту

- **Timeout:** минимум 180 секунд для /v1/chat, 60 — для остального
- **Retry:** на 5xx и timeout — до 2 попыток с exponential backoff
- **Idempotency-Key:** для POST — заголовок с уникальным ID запроса
- **Rate limiting:** проект по умолчанию 60 req/min (настраивается)

---

## Laravel (PHP 8.2+)

### Установка

    composer require guzzlehttp/guzzle

### config/services.php

    'nexus_ai' => [
        'base_url' => env('NEXUS_AI_URL', 'http://31.128.38.96/api'),
        'admin_key' => env('NEXUS_AI_ADMIN_KEY'),
        'project_key' => env('NEXUS_AI_PROJECT_KEY'),
        'timeout' => env('NEXUS_AI_TIMEOUT', 180),
    ],

### .env

    NEXUS_AI_URL=http://31.128.38.96/api
    NEXUS_AI_ADMIN_KEY=<ваш admin-ключ>
    NEXUS_AI_PROJECT_KEY=<ваш project-ключ>
    NEXUS_AI_TIMEOUT=180

### app/Services/NexusAiClient.php

    <?php

    namespace App\Services;

    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;

    class NexusAiClient
    {
        private Client $http;
        private string $baseUrl;
        private string $apiKey;

        public function __construct(?string $key = null)
        {
            $this->baseUrl = rtrim(config('services.nexus_ai.base_url'), '/');
            $this->apiKey = $key ?? config('services.nexus_ai.project_key');
            $this->http = new Client([
                'timeout' => config('services.nexus_ai.timeout', 180),
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
        }

        public function chat(array $payload): array
        {
            $response = $this->http->post("{$this->baseUrl}/v1/chat", [
                'json' => $payload,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }

        public function streamChat(array $payload): \Psr\Http\Message\ResponseInterface
        {
            return $this->http->post("{$this->baseUrl}/v1/chat/stream", [
                'json' => $payload,
                'stream' => true,
            ]);
        }

        public function uploadDocument(string $projectId, string $filePath): array
        {
            $response = $this->http->post("{$this->baseUrl}/v1/projects/{$projectId}/documents", [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath),
                    ],
                ],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }

        public function listExperts(): array
        {
            $response = $this->http->get("{$this->baseUrl}/v1/experts");
            return json_decode($response->getBody()->getContents(), true);
        }

        public function createConversation(string $projectId, ?string $title = null): array
        {
            $response = $this->http->post("{$this->baseUrl}/v1/projects/{$projectId}/conversations", [
                'json' => ['title' => $title],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }

        public function getMessages(string $conversationId, int $limit = 50, ?string $beforeId = null): array
        {
            $query = ['limit' => $limit];
            if ($beforeId) {
                $query['before_id'] = $beforeId;
            }
            $response = $this->http->get("{$this->baseUrl}/v1/conversations/{$conversationId}/messages", [
                'query' => $query,
            ]);
            return json_decode($response->getBody()->getContents(), true);
        }
    }

### app/Http/Controllers/NexusController.php

    <?php

    namespace App\Http\Controllers;

    use App\Services\NexusAiClient;
    use Illuminate\Http\Request;

    class NexusController extends Controller
    {
        public function __construct(private NexusAiClient $nexus) {}

        public function chat(Request $request)
        {
            $validated = $request->validate([
                'message' => 'required|string|max:32000',
                'expert' => 'nullable|string',
                'experts' => 'nullable|array',
                'project_id' => 'nullable|uuid',
                'conversation_id' => 'nullable|uuid',
                'thinking' => 'boolean',
                'use_rag' => 'boolean',
            ]);

            $response = $this->nexus->chat($validated);
            return response()->json($response);
        }
    }

### routes/api.php

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/nexus/chat', [NexusController::class, 'chat']);
    });

### Пример использования

    $client = new NexusAiClient();
    $result = $client->chat([
        'message' => 'Что такое API Gateway?',
        'expert' => 'system_architect',
        'use_rag' => true,
        'project_id' => $projectId,
    ]);

    echo $result['content'];

### Проксирование SSE-стрима

В Laravel SSE можно проксировать через контроллер:

    public function stream(Request $request)
    {
        $upstream = $this->nexus->streamChat($request->all());

        return response()->stream(function () use ($upstream) {
            $body = $upstream->getBody();
            while (!$body->eof()) {
                echo $body->read(4096);
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

В nginx (или в Laravel за прокси) отключить буферизацию:

    proxy_buffering off;
    proxy_cache off;

---

## Nest.js (TypeScript)

### Установка

    npm install @nestjs/axios axios rxjs

### src/nexus-ai/nexus-ai.module.ts

    import { Module } from '@nestjs/common';
    import { HttpModule } from '@nestjs/axios';
    import { NexusAiService } from './nexus-ai.service';
    import { NexusAiController } from './nexus-ai.controller';

    @Module({
      imports: [
        HttpModule.register({
          timeout: 180_000,
          maxRedirects: 5,
        }),
      ],
      providers: [NexusAiService],
      controllers: [NexusAiController],
      exports: [NexusAiService],
    })
    export class NexusAiModule {}

### src/nexus-ai/nexus-ai.service.ts

    import { Injectable, Logger } from '@nestjs/common';
    import { HttpService } from '@nestjs/axios';
    import { ConfigService } from '@nestjs/config';
    import { firstValueFrom } from 'rxjs';
    import FormData from 'form-data';
    import { AxiosResponse } from 'axios';

    export interface ChatDto {
      message: string;
      expert?: string;
      experts?: string[];
      project_id?: string;
      conversation_id?: string;
      thinking?: boolean;
      use_rag?: boolean;
      rag_top_k?: number;
      document_ids?: string[];
    }

    export interface ChatResponse {
      mode: string;
      content?: string;
      aggregated?: string;
      sources?: Array<{
        index: number;
        document_name?: string;
        chunk_index: number;
        score: number;
      }>;
      elapsed_s: number;
      message_ids?: { user: string; assistant: string };
    }

    @Injectable()
    export class NexusAiService {
      private readonly logger = new Logger(NexusAiService.name);
      private readonly baseUrl: string;
      private readonly apiKey: string;

      constructor(
        private readonly http: HttpService,
        private readonly config: ConfigService,
      ) {
        this.baseUrl = config.get('NEXUS_AI_URL', 'http://31.128.38.96/api');
        this.apiKey = config.get('NEXUS_AI_PROJECT_KEY');
      }

      private get headers() {
        return {
          'X-API-Key': this.apiKey,
          'Content-Type': 'application/json',
        };
      }

      async chat(dto: ChatDto): Promise<ChatResponse> {
        const { data } = await firstValueFrom(
          this.http.post<ChatResponse>(`${this.baseUrl}/v1/chat`, dto, {
            headers: this.headers,
          }),
        );
        return data;
      }

      async routePreview(message: string, expert?: string, experts?: string[]) {
        const { data } = await firstValueFrom(
          this.http.post(`${this.baseUrl}/v1/chat/route`,
            { message, expert, experts },
            { headers: this.headers },
          ),
        );
        return data;
      }

      async listExperts() {
        const { data } = await firstValueFrom(
          this.http.get(`${this.baseUrl}/v1/experts`, { headers: this.headers }),
        );
        return data.experts;
      }

      async uploadDocument(projectId: string, file: Buffer, filename: string) {
        const form = new FormData();
        form.append('file', file, filename);

        const { data } = await firstValueFrom(
          this.http.post(
            `${this.baseUrl}/v1/projects/${projectId}/documents`,
            form,
            {
              headers: {
                ...this.headers,
                ...form.getHeaders(),
              },
            },
          ),
        );
        return data;
      }

      async createConversation(projectId: string, title?: string) {
        const { data } = await firstValueFrom(
          this.http.post(
            `${this.baseUrl}/v1/projects/${projectId}/conversations`,
            { title },
            { headers: this.headers },
          ),
        );
        return data;
      }

      async getMessages(conversationId: string, limit = 50, beforeId?: string) {
        const params: any = { limit };
        if (beforeId) params.before_id = beforeId;

        const { data } = await firstValueFrom(
          this.http.get(
            `${this.baseUrl}/v1/conversations/${conversationId}/messages`,
            { headers: this.headers, params },
          ),
        );
        return data;
      }

      // SSE-стрим — возвращаем Observable
      streamChat(dto: ChatDto): AsyncGenerator<any> {
        const self = this;
        return (async function* () {
          const response = await fetch(`${self.baseUrl}/v1/chat/stream`, {
            method: 'POST',
            headers: self.headers,
            body: JSON.stringify(dto),
          });

          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const reader = response.body!.getReader();
          const decoder = new TextDecoder();
          let buffer = '';

          while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const events = buffer.split('\n\n');
            buffer = events.pop() || '';

            for (const ev of events) {
              const eventMatch = ev.match(/^event: (.+)$/m);
              const dataMatch = ev.match(/^data: (.+)$/m);
              if (!dataMatch) continue;
              yield {
                type: eventMatch ? eventMatch[1] : 'message',
                data: JSON.parse(dataMatch[1]),
              };
            }
          }
        })();
      }
    }

### src/nexus-ai/nexus-ai.controller.ts

    import { Controller, Post, Body, Get, Param, UseGuards } from '@nestjs/common';
    import { NexusAiService, ChatDto } from './nexus-ai.service';

    @Controller('nexus')
    export class NexusAiController {
      constructor(private readonly nexus: NexusAiService) {}

      @Post('chat')
      async chat(@Body() dto: ChatDto) {
        return this.nexus.chat(dto);
      }

      @Get('experts')
      async listExperts() {
        return this.nexus.listExperts();
      }

      @Get('conversations/:id/messages')
      async getMessages(
        @Param('id') id: string,
        // query params через @Query() при необходимости
      ) {
        return this.nexus.getMessages(id);
      }
    }

### .env

    NEXUS_AI_URL=http://31.128.38.96/api
    NEXUS_AI_PROJECT_KEY=<ваш project-ключ>

### app.module.ts

    import { NexusAiModule } from './nexus-ai/nexus-ai.module';

    @Module({
      imports: [
        ConfigModule.forRoot(),
        NexusAiModule,
        // ...
      ],
    })
    export class AppModule {}

---

## Python (httpx)

    import httpx
    from typing import Optional, List, Dict, Any

    class NexusClient:
        def __init__(self, base_url: str, api_key: str, timeout: int = 180):
            self.base_url = base_url.rstrip("/")
            self.client = httpx.Client(
                timeout=timeout,
                headers={"X-API-Key": api_key, "Content-Type": "application/json"},
            )

        def chat(
            self,
            message: str,
            expert: Optional[str] = None,
            experts: Optional[List[str]] = None,
            project_id: Optional[str] = None,
            thinking: bool = False,
            use_rag: bool = True,
        ) -> Dict[str, Any]:
            payload = {
                "message": message,
                "thinking": thinking,
                "use_rag": use_rag,
            }
            if expert:
                payload["expert"] = expert
            if experts:
                payload["experts"] = experts
            if project_id:
                payload["project_id"] = project_id

            r = self.client.post(f"{self.base_url}/v1/chat", json=payload)
            r.raise_for_status()
            return r.json()

        def stream_chat(self, message: str, **kwargs):
            """Генератор SSE-событий."""
            payload = {"message": message, **kwargs}
            with self.client.stream(
                "POST",
                f"{self.base_url}/v1/chat/stream",
                json=payload,
            ) as response:
                response.raise_for_status()
                for line in response.iter_lines():
                    if line.startswith("event: "):
                        event_type = line[7:].strip()
                    elif line.startswith("data: "):
                        import json
                        yield event_type, json.loads(line[6:])

        def upload_document(self, project_id: str, file_path: str) -> Dict[str, Any]:
            with open(file_path, "rb") as f:
                files = {"file": (file_path.split("/")[-1], f)}
                # Content-Type для multipart устанавливается автоматически
                r = self.client.post(
                    f"{self.base_url}/v1/projects/{project_id}/documents",
                    files=files,
                    headers={"X-API-Key": self.client.headers["X-API-Key"]},
                )
                r.raise_for_status()
                return r.json()

    # Использование
    client = NexusClient(
        base_url="http://31.128.38.96/api",
        api_key="nx_...",
    )

    result = client.chat(
        message="Что такое API Gateway?",
        expert="system_architect",
        use_rag=False,
    )
    print(result["content"])

    # Стрим
    for event_type, data in client.stream_chat(
        message="Расскажи про монолиты",
        expert="system_architect",
    ):
        if event_type == "token":
            print(data["delta"], end="", flush=True)
        elif event_type == "done":
            print()
            print(f"Готово за {data['elapsed_s']}s")

---

## cURL

### Chat (одиночный)

    curl -X POST http://31.128.38.96/api/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Что такое API Gateway?",
        "expert": "system_architect",
        "use_rag": false
      }'

### Chat (оркестрация)

    curl -X POST http://31.128.38.96/api/v1/chat \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Оцени проект платёжной системы",
        "experts": ["project_scoring", "fintech", "digital_law"]
      }'

### SSE-стрим

    curl -N -X POST http://31.128.38.96/api/v1/chat/stream \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"message": "Привет", "expert": "system_architect"}'

### Загрузка документа

    curl -X POST http://31.128.38.96/api/v1/projects/$PROJECT_ID/documents \
      -H "X-API-Key: $ADMIN_KEY" \
      -F "file=@/path/to/document.pdf"

---

## Ключевые сценарии

### 1. Создать проект и получить ключ

    curl -X POST http://31.128.38.96/api/v1/projects \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"name": "Мой проект", "external_id": "laravel-123"}'

    # Сохраните id из ответа
    PROJECT_ID=...

    curl -X POST http://31.128.38.96/api/v1/projects/$PROJECT_ID/keys \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"name": "Laravel"}'

    # Сохраните key — показывается один раз

### 2. Загрузить документ и задать вопрос

    curl -X POST http://31.128.38.96/api/v1/projects/$PROJECT_ID/documents \
      -H "X-API-Key: $PROJECT_KEY" \
      -F "file=@/path/to/file.pdf"

    # Дождитесь status=ready
    curl -H "X-API-Key: $PROJECT_KEY" \
      http://31.128.38.96/api/v1/documents/$DOC_ID/status

    # Вопрос с RAG
    curl -X POST http://31.128.38.96/api/v1/chat \
      -H "X-API-Key: $PROJECT_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "О чём этот документ?",
        "project_id": "'$PROJECT_ID'",
        "use_rag": true,
        "document_ids": ["'$DOC_ID'"]
      }'

### 3. Диалог с историей

    # Создать диалог
    curl -X POST http://31.128.38.96/api/v1/projects/$PROJECT_ID/conversations \
      -H "X-API-Key: $PROJECT_KEY" \
      -H "Content-Type: application/json" \
      -d '{"title": "Обсуждение архитектуры"}'

    # Отправить сообщение с сохранением
    curl -X POST http://31.128.38.96/api/v1/chat \
      -H "X-API-Key: $PROJECT_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "message": "Что такое монолит?",
        "expert": "system_architect",
        "project_id": "'$PROJECT_ID'",
        "conversation_id": "'$CONV_ID'",
        "save_to_conversation": true
      }'

    # Получить историю
    curl -H "X-API-Key: $PROJECT_KEY" \
      http://31.128.38.96/api/v1/conversations/$CONV_ID/messages

---

## Мониторинг интеграции

- **Healthcheck:** GET /health — 200 {"status":"ok"}
- **System health:** GET /v1/admin/system-health (admin) — состояние всех сервисов
- **Rate limit headers:** X-RateLimit-Limit, X-RateLimit-Remaining в каждом ответе
- **Request ID:** заголовок X-Request-ID в каждом ответе — для трассировки
- **Аудит:** GET /v1/admin/audit (admin) — все действия с фильтрами

---

| KB @CerberRus00 - Nexus Invest Team
