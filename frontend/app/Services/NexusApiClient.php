<?php

namespace App\Services;

use App\Exceptions\NexusApiException;
use App\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class NexusApiClient
{
    /**
     * @return list<NexusExpert>
     */
    public function listExperts(): array
    {
        $adminKey = config('services.nexus_ai.admin_key');

        if (! is_string($adminKey) || trim($adminKey) === '') {
            throw NexusApiException::missingConfiguration('NEXUS_AI_ADMIN_KEY');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withHeader('X-API-Key', $adminKey)
                ->connectTimeout((int) config('services.nexus_ai.connect_timeout', 5))
                ->timeout((int) config('services.nexus_ai.timeout', 20))
                ->get('/v1/experts/all');
        } catch (ConnectionException $exception) {
            throw new NexusApiException('Could not connect to NEXUS AI.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new NexusApiException(
                'NEXUS AI rejected the experts request.',
                statusCode: $response->status(),
            );
        }

        return $this->parseExperts($response);
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
