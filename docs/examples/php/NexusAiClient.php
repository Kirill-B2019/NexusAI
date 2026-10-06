<?php

/**
 * NEXUS AI — Laravel/PHP клиент.
 *
 * Установка:
 *   composer require guzzlehttp/guzzle
 *
 * Конфиг в config/services.php:
 *   'nexus_ai' => [
 *       'base_url' => env('NEXUS_AI_URL', 'http://31.128.38.96/api'),
 *       'api_key' => env('NEXUS_AI_KEY'),
 *       'timeout' => env('NEXUS_AI_TIMEOUT', 300),
 *   ],
 *
 * Использование:
 *   $client = new NexusAiClient();
 *   $result = $client->chat('Что такое API Gateway?', expert: 'system_architect');
 *   echo $result['content'];
 */

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class NexusAiClient
{
    private Client $http;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->baseUrl = rtrim(config('services.nexus_ai.base_url', env('NEXUS_AI_URL', 'http://31.128.38.96/api')), '/');
        $this->apiKey = $apiKey
            ?? config('services.nexus_ai.api_key')
            ?? env('NEXUS_AI_KEY', '');

        $this->http = new Client([
            'timeout' => config('services.nexus_ai.timeout', 300),
            'headers' => [
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    // ─── System ──────────────────────────────────────────
    public function health(): array
    {
        return $this->get('/health');
    }

    public function version(): array
    {
        return $this->get('/version');
    }

    // ─── Experts ─────────────────────────────────────────
    public function listExperts(bool $enabledOnly = true): array
    {
        $url = $enabledOnly ? '/v1/experts' : '/v1/experts/all';
        $response = $this->get($url);
        return $response['experts'] ?? [];
    }

    // ─── Projects ────────────────────────────────────────
    public function listProjects(): array
    {
        $response = $this->get('/v1/projects');
        return $response['projects'] ?? [];
    }

    public function createProject(string $name, ?string $externalId = null): array
    {
        $payload = ['name' => $name];
        if ($externalId !== null) {
            $payload['external_id'] = $externalId;
        }
        return $this->post('/v1/projects', $payload);
    }

    // ─── Documents ───────────────────────────────────────
    public function uploadDocument(string $projectId, string $filePath): array
    {
        $response = $this->http->post(
            "{$this->baseUrl}/v1/projects/{$projectId}/documents",
            [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath),
                    ],
                ],
                'headers' => ['X-API-Key' => $this->apiKey],
            ]
        );
        return $this->decode($response);
    }

    public function listDocuments(string $projectId): array
    {
        $response = $this->get("/v1/projects/{$projectId}/documents");
        return $response['documents'] ?? [];
    }

    public function documentStatus(string $documentId): array
    {
        return $this->get("/v1/documents/{$documentId}/status");
    }

    // ─── Chat ────────────────────────────────────────────
    public function chat(
        string $message,
        ?string $expert = null,
        ?array $experts = null,
        bool $thinking = false,
        ?string $projectId = null,
        ?string $conversationId = null,
        bool $useRag = true,
        int $ragTopK = 4,
        ?array $documentIds = null,
        bool $saveToConversation = false,
    ): array {
        $payload = [
            'message' => $message,
            'thinking' => $thinking,
            'use_rag' => $useRag,
            'rag_top_k' => $ragTopK,
            'orchestrate' => $expert === null && $experts === null,
        ];

        if ($expert !== null) {
            $payload['expert'] = $expert;
            $payload['orchestrate'] = false;
        }
        if ($experts !== null) {
            $payload['experts'] = $experts;
        }
        if ($projectId !== null) {
            $payload['project_id'] = $projectId;
        }
        if ($conversationId !== null) {
            $payload['conversation_id'] = $conversationId;
            $payload['save_to_conversation'] = $saveToConversation;
        }
        if ($documentIds !== null) {
            $payload['document_ids'] = $documentIds;
        }

        return $this->post('/v1/chat', $payload);
    }

    /**
     * SSE-стрим. Возвращает поток — читайте через ->getBody()->read().
     */
    public function streamChat(array $payload): ResponseInterface
    {
        return $this->http->post("{$this->baseUrl}/v1/chat/stream", [
            'json' => $payload,
            'stream' => true,
            'headers' => ['X-API-Key' => $this->apiKey],
        ]);
    }

    // ─── Диалоги ─────────────────────────────────────────
    public function createConversation(string $projectId, ?string $title = null): array
    {
        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        return $this->post("/v1/projects/{$projectId}/conversations", $payload);
    }

    public function getMessages(
        string $conversationId,
        int $limit = 50,
        ?string $beforeId = null,
    ): array {
        $query = ['limit' => $limit];
        if ($beforeId !== null) {
            $query['before_id'] = $beforeId;
        }
        return $this->get("/v1/conversations/{$conversationId}/messages", $query);
    }

    // ─── Админ ───────────────────────────────────────────
    public function adminStats(): array
    {
        return $this->get('/v1/admin/stats');
    }

    public function systemHealth(): array
    {
        return $this->get('/v1/admin/system-health');
    }

    // ─── Низкоуровневые ──────────────────────────────────
    private function get(string $path, array $query = []): array
    {
        $url = "{$this->baseUrl}{$path}";
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        try {
            $response = $this->http->get($url);
            return $this->decode($response);
        } catch (RequestException $e) {
            $this->handleError($e);
            return [];
        }
    }

    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->http->post("{$this->baseUrl}{$path}", ['json' => $payload]);
            return $this->decode($response);
        } catch (RequestException $e) {
            $this->handleError($e);
            return [];
        }
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function handleError(RequestException $e): void
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body = $e->getResponse()?->getBody()->getContents() ?? '';
        throw new \RuntimeException("NEXUS AI error {$status}: {$body}", $status, $e);
    }
}

// ---
// | KB @CerberRus00 - Nexus Invest Team
