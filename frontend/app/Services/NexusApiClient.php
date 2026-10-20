<?php

namespace App\Services;

use App\Exceptions\NexusApiException;
use App\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NexusApiClient
{
    /**
     * @return list<NexusExpert>
     */
    public function listExperts(): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get('/v1/experts/all'),
            'NEXUS AI rejected the experts request.',
        );

        return $this->parseExperts($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function createTrainingDatasetBatch(string $expert, string $filePath, string $filename): array
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new NexusApiException('Could not read the uploaded training dataset.');
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http
                ->attach('file', $contents, $filename)
                ->post('/v1/admin/training/datasets/batches', ['expert' => $expert]),
            'NEXUS AI could not create the training dataset batch.',
        );
        $attributes = $response->json();

        if (
            ! is_array($attributes)
            || ! is_string($attributes['id'] ?? null)
            || ! is_array($attributes['validation'] ?? null)
            || ! is_array($attributes['validation']['examples'] ?? null)
            || ! array_is_list($attributes['validation']['examples'])
        ) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * @param  array{expert?: string, status?: string, limit?: int, offset?: int}  $filters
     * @return array{batches: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listTrainingDatasetBatches(array $filters = []): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/admin/training/datasets/batches',
                $filters,
            ),
            'NEXUS AI could not list training dataset batches.',
        );
        $attributes = $response->json();
        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        $batches = $attributes['batches'] ?? null;
        $total = $attributes['total'] ?? null;
        $limit = $attributes['limit'] ?? null;
        $offset = $attributes['offset'] ?? null;

        if (
            ! is_array($batches)
            || ! array_is_list($batches)
            || ! is_int($total)
            || ! is_int($limit)
            || ! is_int($offset)
        ) {
            throw NexusApiException::invalidResponse();
        }

        foreach ($batches as $batch) {
            if (! is_array($batch) || ! is_string($batch['id'] ?? null)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return compact('batches', 'total', 'limit', 'offset');
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrainingDatasetBatch(string $batchId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/admin/training/datasets/batches/'.rawurlencode($batchId),
            ),
            'NEXUS AI could not get the training dataset batch.',
        );
        $attributes = $response->json();

        if (
            ! is_array($attributes)
            || ! is_string($attributes['id'] ?? null)
            || ! is_array($attributes['validation'] ?? null)
            || ! is_array($attributes['validation']['examples'] ?? null)
            || ! array_is_list($attributes['validation']['examples'])
        ) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    public function approveTrainingDatasetBatch(string $batchId, bool $duplicatesReviewed): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/datasets/batches/'.rawurlencode($batchId).'/approve',
                ['duplicates_reviewed' => $duplicatesReviewed],
            ),
            'NEXUS AI could not approve the training dataset batch.',
        );
    }

    public function rejectTrainingDatasetBatch(string $batchId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/datasets/batches/'.rawurlencode($batchId).'/reject',
            ),
            'NEXUS AI could not reject the training dataset batch.',
        );
    }

    /**
     * @param  array{question?: string, answer?: string, metadata?: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    public function updateTrainingDatasetBatchExample(
        string $batchId,
        int $row,
        array $payload,
    ): array {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->patch(
                '/v1/admin/training/datasets/batches/'.rawurlencode($batchId)
                    .'/examples/'.$row,
                $payload,
            ),
            'NEXUS AI could not update the training dataset example.',
        );
        $attributes = $response->json();
        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteTrainingDatasetBatchExample(string $batchId, int $row): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/admin/training/datasets/batches/'.rawurlencode($batchId)
                    .'/examples/'.$row,
            ),
            'NEXUS AI could not delete the training dataset example.',
        );
        $attributes = $response->json();
        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * @param  array{expert?: string, limit?: int, offset?: int}  $filters
     * @return array{releases: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listTrainingDatasetReleases(array $filters = []): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/admin/training/datasets/releases',
                $filters,
            ),
            'NEXUS AI could not list training dataset releases.',
        );
        $attributes = $response->json();
        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        $releases = $attributes['releases'] ?? null;
        $total = $attributes['total'] ?? null;
        $limit = $attributes['limit'] ?? null;
        $offset = $attributes['offset'] ?? null;

        if (
            ! is_array($releases)
            || ! array_is_list($releases)
            || ! is_int($total)
            || ! is_int($limit)
            || ! is_int($offset)
        ) {
            throw NexusApiException::invalidResponse();
        }

        foreach ($releases as $release) {
            if (! is_array($release) || ! is_string($release['id'] ?? null)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return compact('releases', 'total', 'limit', 'offset');
    }

    /**
     * @return array<string, mixed>
     */
    public function createTrainingDatasetRelease(string $expert): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/datasets/releases',
                ['expert' => $expert],
            ),
            'NEXUS AI could not create the training dataset release.',
        );
        $attributes = $response->json();

        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * Составляет черновики Q/A для эксперта через веб-поиск на стороне NEXUS AI.
     *
     * @return array{expert: string, query: string, sources: list<array<string, mixed>>, drafts: list<array<string, mixed>>}
     */
    public function draftTrainingExamples(string $expert, string $query, int $count): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/research/',
                [
                    'expert' => $expert,
                    'query' => $query,
                    'count' => $count,
                ],
            ),
            'NEXUS AI could not draft training examples.',
        );
        $attributes = $response->json();

        if (
            ! is_array($attributes)
            || ! is_string($attributes['expert'] ?? null)
            || ! is_string($attributes['query'] ?? null)
            || ! is_array($attributes['sources'] ?? null)
            || ! array_is_list($attributes['sources'])
            || ! is_array($attributes['drafts'] ?? null)
            || ! array_is_list($attributes['drafts'])
        ) {
            throw NexusApiException::invalidResponse();
        }

        foreach ($attributes['drafts'] as $draft) {
            if (
                ! is_array($draft)
                || ! is_string($draft['question'] ?? null)
                || ! is_string($draft['answer'] ?? null)
            ) {
                throw NexusApiException::invalidResponse();
            }
        }

        return $attributes;
    }

    /**
     * Покрытие тем по утверждённым парам эксперта.
     *
     * @return array{expert: string, approved_examples: int, target: int|null, topics: list<array<string, mixed>>, missing_topics: list<string>}
     */
    public function trainingDatasetStats(string $expert): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/admin/training/datasets/stats',
                ['expert' => $expert],
            ),
            'NEXUS AI could not load training dataset stats.',
        );
        $attributes = $response->json();

        if (
            ! is_array($attributes)
            || ! is_string($attributes['expert'] ?? null)
            || ! is_int($attributes['approved_examples'] ?? null)
            || ! is_array($attributes['topics'] ?? null)
            || ! array_is_list($attributes['topics'])
            || ! is_array($attributes['missing_topics'] ?? null)
            || ! array_is_list($attributes['missing_topics'])
        ) {
            throw NexusApiException::invalidResponse();
        }

        $target = $attributes['target'] ?? null;
        if ($target !== null && ! is_int($target)) {
            throw NexusApiException::invalidResponse();
        }

        $attributes['target'] = $target;

        return $attributes;
    }

    /**
     * @param  array{expert?: string, status?: string, limit?: int, offset?: int}  $filters
     * @return array{adapters: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listLoraAdapters(array $filters = []): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/admin/training/adapters/',
                $filters,
            ),
            'NEXUS AI could not list LoRA adapters.',
        );
        $attributes = $response->json();
        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        $adapters = $attributes['adapters'] ?? null;
        $total = $attributes['total'] ?? null;
        $limit = $attributes['limit'] ?? null;
        $offset = $attributes['offset'] ?? null;

        if (
            ! is_array($adapters)
            || ! array_is_list($adapters)
            || ! is_int($total)
            || ! is_int($limit)
            || ! is_int($offset)
        ) {
            throw NexusApiException::invalidResponse();
        }

        foreach ($adapters as $adapter) {
            if (! is_array($adapter) || ! is_string($adapter['id'] ?? null)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return compact('adapters', 'total', 'limit', 'offset');
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadLoraAdapter(
        string $expert,
        string $baseModel,
        string $filePath,
        string $filename,
        ?string $datasetReleaseId = null,
        ?string $notes = null,
    ): array {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new NexusApiException('Could not read the uploaded LoRA adapter.');
        }

        $payload = [
            'expert' => $expert,
            'base_model' => $baseModel,
        ];
        if ($datasetReleaseId !== null && $datasetReleaseId !== '') {
            $payload['dataset_release_id'] = $datasetReleaseId;
        }
        if ($notes !== null && $notes !== '') {
            $payload['notes'] = $notes;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http
                ->attach('file', $contents, $filename)
                ->post('/v1/admin/training/adapters/', $payload),
            'NEXUS AI could not upload the LoRA adapter.',
        );
        $attributes = $response->json();

        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishLoraAdapter(string $adapterId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/adapters/'.rawurlencode($adapterId).'/publish',
            ),
            'NEXUS AI could not publish the LoRA adapter.',
        );
        $attributes = $response->json();
        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function rollbackLoraAdapter(string $adapterId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/admin/training/adapters/'.rawurlencode($adapterId).'/rollback',
            ),
            'NEXUS AI could not roll back the LoRA adapter.',
        );
        $attributes = $response->json();
        if (! is_array($attributes) || ! is_string($attributes['id'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return $attributes;
    }

    public function deleteLoraAdapter(string $adapterId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/admin/training/adapters/'.rawurlencode($adapterId),
            ),
            'NEXUS AI could not delete the LoRA adapter.',
        );
    }

    /**
     * @return list<NexusProject>
     */
    public function listProjects(): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get('/v1/projects'),
            'NEXUS AI rejected the project list request.',
        );

        return $this->parseProjectList($response);
    }

    public function getProject(string $projectId): NexusProject
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get('/v1/projects/'.rawurlencode($projectId)),
            'NEXUS AI rejected the project request.',
        );

        return $this->parseProject($response);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createProject(string $name, ?string $description, array $metadata): NexusProject
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post('/v1/projects', [
                'name' => $name,
                'description' => $description,
                'metadata' => (object) $metadata,
            ]),
            'NEXUS AI could not create the project.',
        );

        return $this->parseProject($response);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateProject(string $projectId, array $changes): NexusProject
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->patch(
                '/v1/projects/'.rawurlencode($projectId),
                $changes,
            ),
            'NEXUS AI could not update the project.',
        );

        return $this->parseProject($response);
    }

    public function deleteProject(string $projectId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete('/v1/projects/'.rawurlencode($projectId)),
            'NEXUS AI could not delete the project.',
        );
    }

    /**
     * @param  list<string>|null  $allowedExperts
     */
    public function createProjectKey(
        string $projectId,
        string $name,
        ?array $allowedExperts = null,
        int $rateLimitPerMinute = 60,
        ?string $expiresAt = null,
        ?string $startsAt = null,
    ): NexusProjectKey {
        $payload = [
            'name' => $name,
            'allowed_experts' => $allowedExperts,
            'rate_limit_per_min' => $rateLimitPerMinute,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
        ];

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/projects/'.rawurlencode($projectId).'/keys',
                $payload,
            ),
            'NEXUS AI could not issue a project API key.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusProjectKey::fromApi($attributes);
    }

    /**
     * @return list<NexusProjectApiKey>
     */
    public function listProjectKeys(string $projectId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/projects/'.rawurlencode($projectId).'/keys',
            ),
            'NEXUS AI could not list project API keys.',
        );
        $attributes = $response->json('keys');

        if (! is_array($attributes) || ! array_is_list($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $key): NexusProjectApiKey {
                if (! is_array($key)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusProjectApiKey::fromApi($key);
            },
            $attributes,
        );
    }

    public function revokeProjectKey(string $projectId, string $keyId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/projects/'.rawurlencode($projectId).'/keys/'.rawurlencode($keyId),
            ),
            'NEXUS AI could not revoke the project API key.',
        );
    }

    /**
     * @return list<NexusDocument>
     */
    public function listDocuments(string $projectId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/projects/'.rawurlencode($projectId).'/documents',
            ),
            'NEXUS AI could not list documents.',
        );

        $documents = $response->json('documents');

        if (! is_array($documents) || ! array_is_list($documents)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $doc): NexusDocument {
                if (! is_array($doc)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusDocument::fromApi($doc);
            },
            $documents,
        );
    }

    public function getDocument(string $documentId): NexusDocument
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/documents/'.rawurlencode($documentId),
            ),
            'NEXUS AI could not get the document.',
        );
        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        $document = NexusDocument::fromApi($attributes);
        if ($document->id !== $documentId) {
            throw NexusApiException::invalidResponse();
        }

        return $document;
    }

    /**
     * @return array{id: string, status: string, chunks_count: int, error: ?string, processed_at: ?string}
     */
    public function getDocumentStatus(string $documentId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/documents/'.rawurlencode($documentId).'/status',
            ),
            'NEXUS AI could not get the document status.',
        );
        $attributes = $response->json();
        $validStatuses = ['pending', 'processing', 'ready', 'failed'];

        if (
            ! is_array($attributes)
            || ! is_string($attributes['id'] ?? null)
            || $attributes['id'] !== $documentId
            || ! is_string($attributes['status'] ?? null)
            || ! in_array($attributes['status'], $validStatuses, true)
            || ! is_int($attributes['chunks_count'] ?? null)
            || (! is_string($attributes['error'] ?? null) && ($attributes['error'] ?? null) !== null)
            || (! is_string($attributes['processed_at'] ?? null) && ($attributes['processed_at'] ?? null) !== null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        if ($attributes['processed_at'] !== null && strtotime($attributes['processed_at']) === false) {
            throw NexusApiException::invalidResponse();
        }

        return [
            'id' => $attributes['id'],
            'status' => $attributes['status'],
            'chunks_count' => $attributes['chunks_count'],
            'error' => $attributes['error'],
            'processed_at' => $attributes['processed_at'],
        ];
    }

    public function uploadDocument(string $projectId, string $filePath, string $originalFilename): NexusDocument
    {
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeader('X-API-Key', config('services.nexus_ai.admin_key'))
            ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
            ->timeout(max(300, (int) config('services.nexus_ai.timeout', 20)))
            ->attach('file', file_get_contents($filePath), $originalFilename)
            ->post('/v1/projects/'.rawurlencode($projectId).'/documents');

        if (! $response->successful()) {
            throw new NexusApiException('NEXUS AI could not upload the document.', statusCode: $response->status());
        }

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusDocument::fromApi($attributes);
    }

    public function deleteDocument(string $documentId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/documents/'.rawurlencode($documentId),
            ),
            'NEXUS AI could not delete the document.',
        );
    }

    public function reindexDocument(string $documentId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/documents/'.rawurlencode($documentId).'/reindex',
            ),
            'NEXUS AI could not reindex the document.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function askDocument(
        string $documentId,
        string $question,
        ?string $expert = null,
        bool $thinking = false,
        int $ragTopK = 6,
        float $ragMinScore = 0.4,
    ): array {
        $payload = [
            'message' => $question,
            'thinking' => $thinking,
            'rag_top_k' => $ragTopK,
            'rag_min_score' => $ragMinScore,
        ];
        if ($expert !== null && $expert !== '') {
            $payload['expert'] = $expert;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/documents/'.rawurlencode($documentId).'/ask',
                $payload,
            ),
            'NEXUS AI could not answer about the document.',
        );

        $result = $response->json();

        if (! is_array($result)) {
            throw NexusApiException::invalidResponse();
        }

        return $result;
    }

    /**
     * Потоковый запрос по конкретному документу через серверный admin API key.
     *
     * @param  array<string, mixed>  $body
     */
    public function streamDocumentQuestion(array $body): Response
    {
        $timeout = max(1, (int) config('services.nexus_ai.stream_timeout', 900));

        try {
            return $this->adminRequest()
                ->accept('text/event-stream')
                ->timeout($timeout)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $timeout,
                ])
                ->post('/v1/chat/stream', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to NEXUS AI.', previous: $exception);
        }
    }

    /**
     * @return list<NexusConversation>
     */
    public function listConversations(string $projectId, ?int $limit = null, ?string $cursor = null): array
    {
        $params = [];
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/projects/'.rawurlencode($projectId).'/conversations',
                $params,
            ),
            'NEXUS AI could not list conversations.',
        );

        $conversations = $response->json('conversations');

        if (! is_array($conversations) || ! array_is_list($conversations)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $conv): NexusConversation {
                if (! is_array($conv)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusConversation::fromApi($conv);
            },
            $conversations,
        );
    }

    /**
     * @return list<NexusConversationMessage>
     */
    public function listConversationMessages(string $conversationId, ?int $limit = null, ?string $cursor = null): array
    {
        $params = [];
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/conversations/'.rawurlencode($conversationId).'/messages',
                $params,
            ),
            'NEXUS AI could not list conversation messages.',
        );

        $messages = $response->json('messages');

        if (! is_array($messages) || ! array_is_list($messages)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $msg): NexusConversationMessage {
                if (! is_array($msg)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusConversationMessage::fromApi($msg);
            },
            $messages,
        );
    }

    /**
     * @return list<NexusDecision>
     */
    public function listDecisions(string $projectId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/projects/'.rawurlencode($projectId).'/decisions',
            ),
            'NEXUS AI could not list decisions.',
        );

        $decisions = $response->json('decisions');

        if (! is_array($decisions) || ! array_is_list($decisions)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $decision): NexusDecision {
                if (! is_array($decision)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusDecision::fromApi($decision);
            },
            $decisions,
        );
    }

    public function createDecision(string $projectId, string $title, string $content): NexusDecision
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/projects/'.rawurlencode($projectId).'/decisions',
                [
                    'title' => $title,
                    'content' => $content,
                ],
            ),
            'NEXUS AI could not create the decision.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusDecision::fromApi($attributes);
    }

    public function updateDecision(string $decisionId, array $changes): NexusDecision
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->patch(
                '/v1/decisions/'.rawurlencode($decisionId),
                $changes,
            ),
            'NEXUS AI could not update the decision.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusDecision::fromApi($attributes);
    }

    public function deleteDecision(string $decisionId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/decisions/'.rawurlencode($decisionId),
            ),
            'NEXUS AI could not delete the decision.',
        );
    }

    /**
     * @return list<NexusTask>
     */
    public function listTasks(string $projectId): array
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get(
                '/v1/projects/'.rawurlencode($projectId).'/tasks',
            ),
            'NEXUS AI could not list tasks.',
        );

        $tasks = $response->json('tasks');

        if (! is_array($tasks) || ! array_is_list($tasks)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $task): NexusTask {
                if (! is_array($task)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusTask::fromApi($task);
            },
            $tasks,
        );
    }

    public function createTask(
        string $projectId,
        string $title,
        ?string $description = null,
        ?string $assigneeExpert = null,
        ?string $dueDate = null,
    ): NexusTask {
        $payload = [
            'title' => $title,
            'description' => $description,
        ];

        if ($assigneeExpert !== null) {
            $payload['assignee_expert'] = $assigneeExpert;
        }

        if ($dueDate !== null) {
            $payload['due_date'] = $dueDate;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post(
                '/v1/projects/'.rawurlencode($projectId).'/tasks',
                $payload,
            ),
            'NEXUS AI could not create the task.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusTask::fromApi($attributes);
    }

    public function updateTask(string $taskId, array $changes): NexusTask
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->patch(
                '/v1/tasks/'.rawurlencode($taskId),
                $changes,
            ),
            'NEXUS AI could not update the task.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusTask::fromApi($attributes);
    }

    public function deleteTask(string $taskId): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/tasks/'.rawurlencode($taskId),
            ),
            'NEXUS AI could not delete the task.',
        );
    }

    /**
     * @return list<NexusAuditEntry>
     */
    public function listAudit(?string $actor = null, ?string $entityType = null, ?int $limit = null): array
    {
        $params = [];
        if ($actor !== null) {
            $params['actor'] = $actor;
        }
        if ($entityType !== null) {
            $params['entity_type'] = $entityType;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get('/v1/admin/audit', $params),
            'NEXUS AI could not list audit entries.',
        );

        $entries = $response->json('entries');

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $entry): NexusAuditEntry {
                if (! is_array($entry)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusAuditEntry::fromApi($entry);
            },
            $entries,
        );
    }

    public function getSystemHealth(): NexusSystemHealth
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->get('/v1/admin/system-health'),
            'NEXUS AI could not report system health.',
        );

        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusSystemHealth::fromApi($attributes);
    }

    public function createExpert(array $attributes): NexusExpert
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->post('/v1/experts', $attributes),
            'NEXUS AI could not create the expert.',
        );

        return $this->parseExpert($response);
    }

    public function updateExpert(string $expertKey, array $changes): NexusExpert
    {
        $response = $this->adminResponse(
            fn (PendingRequest $http): Response => $http->patch(
                '/v1/experts/'.rawurlencode($expertKey),
                $changes,
            ),
            'NEXUS AI could not update the expert.',
        );

        return $this->parseExpert($response);
    }

    public function deleteExpert(string $expertKey): void
    {
        $this->adminResponse(
            fn (PendingRequest $http): Response => $http->delete(
                '/v1/experts/'.rawurlencode($expertKey),
            ),
            'NEXUS AI could not delete the expert.',
        );
    }

    /**
     * Потоковый запрос чата к NEXUS AI.
     *
     * @param  array<string, mixed>  $body  Тело запроса (message, project_id, expert/experts, use_rag и др.)
     */
    public function streamChat(Project $project, array $body): Response
    {
        try {
            $projectKey = $project->api_key;
        } catch (DecryptException $exception) {
            throw new NexusApiException('The project API key could not be decrypted.', previous: $exception);
        }

        if (! is_string($projectKey) || trim($projectKey) === '') {
            throw NexusApiException::missingConfiguration('project API key');
        }

        $timeout = max(1, (int) config('services.nexus_ai.stream_timeout', 900));

        // Добавляем project_id если не передан
        if (! isset($body['project_id'])) {
            $body['project_id'] = $project->nexus_project_id;
        }

        // use_rag по умолчанию true
        if (! isset($body['use_rag'])) {
            $body['use_rag'] = true;
        }

        try {
            return Http::baseUrl($this->baseUrl())
                ->accept('text/event-stream')
                ->withHeader('X-API-Key', $projectKey)
                ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
                ->timeout($timeout)
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $timeout,
                ])
                ->post('/v1/chat/stream', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI chat stream.', previous: $exception);
        }
    }

    /**
     * Предпросмотр маршрутизации к NEXUS AI.
     *
     * @param  array<string, mixed>  $body  Тело запроса (message, project_id)
     */
    public function previewRoute(Project $project, array $body): Response
    {
        try {
            $projectKey = $project->api_key;
        } catch (DecryptException $exception) {
            throw new NexusApiException('The project API key could not be decrypted.', previous: $exception);
        }

        if (! is_string($projectKey) || trim($projectKey) === '') {
            throw NexusApiException::missingConfiguration('project API key');
        }

        if (! isset($body['project_id'])) {
            $body['project_id'] = $project->nexus_project_id;
        }

        try {
            return Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withHeader('X-API-Key', $projectKey)
                ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
                ->timeout(max(30, (int) config('services.nexus_ai.timeout', 20)))
                ->post('/v1/chat/route', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI route preview.', previous: $exception);
        }
    }

    /**
     * RAG-анализ: что говорят документы о...
     *
     * @param  array<string, mixed>  $body  Тело запроса (message, document_ids, project_id)
     */
    public function ragAnalysis(Project $project, array $body): Response
    {
        try {
            $projectKey = $project->api_key;
        } catch (DecryptException $exception) {
            throw new NexusApiException('The project API key could not be decrypted.', previous: $exception);
        }

        if (! is_string($projectKey) || trim($projectKey) === '') {
            throw NexusApiException::missingConfiguration('project API key');
        }

        if (! isset($body['project_id'])) {
            $body['project_id'] = $project->nexus_project_id;
        }

        // use_rag по умолчанию true
        if (! isset($body['use_rag'])) {
            $body['use_rag'] = true;
        }

        try {
            return Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withHeader('X-API-Key', $projectKey)
                ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
                ->timeout(max(120, (int) config('services.nexus_ai.timeout', 60)))
                ->post('/v1/documents/analyze', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI RAG analysis.', previous: $exception);
        }
    }

    /**
     * Сравнение документов.
     *
     * @param  array<string, mixed>  $body  Тело запроса (document_ids, comparison_type)
     */
    public function compareDocuments(Project $project, array $body): Response
    {
        try {
            $projectKey = $project->api_key;
        } catch (DecryptException $exception) {
            throw new NexusApiException('The project API key could not be decrypted.', previous: $exception);
        }

        if (! is_string($projectKey) || trim($projectKey) === '') {
            throw NexusApiException::missingConfiguration('project API key');
        }

        if (! isset($body['project_id'])) {
            $body['project_id'] = $project->nexus_project_id;
        }

        try {
            return Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withHeader('X-API-Key', $projectKey)
                ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
                ->timeout(max(120, (int) config('services.nexus_ai.timeout', 60)))
                ->post('/v1/documents/compare', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI document comparison.', previous: $exception);
        }
    }

    /**
     * Извлечение ключевых данных из документов.
     *
     * @param  array<string, mixed>  $body  Тело запроса (document_ids, extraction_schema)
     */
    public function extractData(Project $project, array $body): Response
    {
        try {
            $projectKey = $project->api_key;
        } catch (DecryptException $exception) {
            throw new NexusApiException('The project API key could not be decrypted.', previous: $exception);
        }

        if (! is_string($projectKey) || trim($projectKey) === '') {
            throw NexusApiException::missingConfiguration('project API key');
        }

        if (! isset($body['project_id'])) {
            $body['project_id'] = $project->nexus_project_id;
        }

        try {
            return Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withHeader('X-API-Key', $projectKey)
                ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
                ->timeout(max(120, (int) config('services.nexus_ai.timeout', 60)))
                ->post('/v1/documents/extract', $body);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI data extraction.', previous: $exception);
        }
    }

    private function baseUrl(): string
    {
        $url = config('services.nexus_ai.url');

        if (! is_string($url) || trim($url) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw NexusApiException::missingConfiguration('NEXUS_AI_URL');
        }

        $urlParts = parse_url($url);

        if (
            ! is_array($urlParts)
            || ! in_array($urlParts['scheme'] ?? null, ['http', 'https'], true)
            || empty($urlParts['host'])
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
        ) {
            throw NexusApiException::missingConfiguration('NEXUS_AI_URL');
        }

        return rtrim($url, '/');
    }

    private function adminRequest(): PendingRequest
    {
        $adminKey = config('services.nexus_ai.admin_key');

        if (! is_string($adminKey) || trim($adminKey) === '') {
            throw NexusApiException::missingConfiguration('NEXUS_AI_ADMIN_KEY');
        }

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withHeader('X-API-Key', $adminKey)
            ->connectTimeout(max(1, (int) config('services.nexus_ai.connect_timeout', 5)))
            ->timeout(max(1, (int) config('services.nexus_ai.timeout', 20)));
    }

    /**
     * @param  \Closure(PendingRequest): Response  $send
     */
    private function adminResponse(\Closure $send, string $failureMessage): Response
    {
        try {
            $response = $send($this->adminRequest());
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to NEXUS AI.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new NexusApiException($failureMessage, statusCode: $response->status());
        }

        return $response;
    }

    /**
     * @return list<NexusProject>
     */
    private function parseProjectList(Response $response): array
    {
        $projects = $response->json('projects');

        if (! is_array($projects) || ! array_is_list($projects)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $project): NexusProject {
                if (! is_array($project)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusProject::fromApi($project);
            },
            $projects,
        );
    }

    private function parseProject(Response $response): NexusProject
    {
        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusProject::fromApi($attributes);
    }

    private function parseExpert(Response $response): NexusExpert
    {
        $attributes = $response->json();

        if (! is_array($attributes)) {
            throw NexusApiException::invalidResponse();
        }

        return NexusExpert::fromApi($attributes);
    }

    /**
     * @return list<NexusExpert>
     */
    private function parseExperts(Response $response): array
    {
        $payload = $response->json();

        Log::debug('NEXUS AI experts API response structure:', [
            'payload_type' => gettype($payload),
            'payload_keys' => is_array($payload) ? array_keys($payload) : null,
            'is_list' => is_array($payload) ? array_is_list($payload) : null,
        ]);

        // Если ответ — массив с полем 'experts', извлекаем его
        if (is_array($payload) && isset($payload['experts']) && is_array($payload['experts'])) {
            $payload = $payload['experts'];
        }

        // Если ответ — массив с полем 'data', извлекаем его
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        // Проверяем что это массив
        if (! is_array($payload)) {
            Log::error('NEXUS AI experts response is not an array.', [
                'payload_type' => gettype($payload),
            ]);
            throw NexusApiException::invalidResponse();
        }

        // Проверяем что это список (если нет — возможно это список с ключами)
        if (! array_is_list($payload)) {
            Log::warning('NEXUS AI experts payload is not a list, trying to parse as array.', [
                'keys' => array_keys($payload),
            ]);
            // Пробуем извлечь значения
            $payload = array_values($payload);
        }

        $result = [];
        foreach ($payload as $index => $expert) {
            if (! is_array($expert)) {
                Log::warning('NEXUS AI expert entry is not an array at index '.$index, [
                    'expert_type' => gettype($expert),
                ]);

                continue;
            }

            try {
                $result[] = NexusExpert::fromApi($expert);
            } catch (\Throwable $exception) {
                Log::warning('NEXUS AI expert parsing failed at index '.$index, [
                    'expert_key' => $expert['key'] ?? 'unknown',
                    'exception' => $exception::class,
                ]);
            }
        }

        return $result;
    }
}
