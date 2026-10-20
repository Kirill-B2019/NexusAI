<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusHealthStatus
{
    public function __construct(
        public string $service,
        public string $status,
        public ?string $message,
        public ?int $latencyMs,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes, ?string $serviceName = null): self
    {
        $service = $attributes['service'] ?? $attributes['name'] ?? $serviceName;
        if (! is_string($service) || $service === '') {
            throw NexusApiException::invalidResponse();
        }

        $status = $attributes['status'] ?? 'unknown';
        if (! is_string($status)) {
            throw NexusApiException::invalidResponse();
        }

        $statusMap = [
            'ok' => 'healthy',
            'healthy' => 'healthy',
            'degraded' => 'degraded',
            'partial' => 'degraded',
            'down' => 'down',
            'unhealthy' => 'down',
            'error' => 'down',
            'unknown' => 'unknown',
        ];
        $status = $statusMap[strtolower($status)] ?? 'unknown';

        $latencyMs = $attributes['latency_ms'] ?? $attributes['latency'] ?? null;
        if ($latencyMs !== null && (! is_int($latencyMs) || $latencyMs < 0)) {
            throw NexusApiException::invalidResponse();
        }

        return new self(
            service: $service,
            status: $status,
            message: is_string($attributes['message'] ?? null)
                ? $attributes['message']
                : (is_string($attributes['error'] ?? null) ? $attributes['error'] : null),
            latencyMs: $latencyMs,
        );
    }

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function isDown(): bool
    {
        return $this->status === 'down';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'healthy' => 'Здоров',
            'degraded' => 'Деградирован',
            'down' => 'Недоступен',
            default => 'Неизвестно',
        };
    }
}
