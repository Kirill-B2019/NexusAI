<?php

namespace App\Services;

use App\Exceptions\NexusApiException;
use App\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
    ): NexusProjectKey {
        $payload = [
            'name' => $name,
            'allowed_experts' => $allowedExperts,
            'rate_limit_per_min' => $rateLimitPerMinute,
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

    public function streamChat(Project $project, string $message): Response
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
                ->post('/v1/chat/stream', [
                    'message' => $message,
                    'project_id' => $project->nexus_project_id,
                    'use_rag' => true,
                ]);
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to the NEXUS AI chat stream.', previous: $exception);
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

    /**
     * @return list<NexusExpert>
     */
    private function parseExperts(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload) && isset($payload['experts']) && is_array($payload['experts'])) {
            $payload = $payload['experts'];
        }

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw NexusApiException::invalidResponse();
        }

        return array_map(
            static function (mixed $expert): NexusExpert {
                if (! is_array($expert)) {
                    throw NexusApiException::invalidResponse();
                }

                return NexusExpert::fromApi($expert);
            },
            $payload,
        );
    }
}
