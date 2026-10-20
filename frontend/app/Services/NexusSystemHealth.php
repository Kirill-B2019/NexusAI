<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusSystemHealth
{
    /**
     * @param  array<string, NexusHealthStatus>  $services
     */
    public function __construct(
        public array $services,
        public ?string $updatedAt,
        public string $overallStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        $services = $attributes['services'] ?? $attributes['services_health'] ?? null;

        if (! is_array($services)) {
            throw NexusApiException::invalidResponse();
        }

        $parsedServices = [];
        foreach ($services as $serviceName => $serviceData) {
            if (! is_array($serviceData)) {
                throw NexusApiException::invalidResponse();
            }
            $name = is_string($serviceName) ? $serviceName : null;
            $service = NexusHealthStatus::fromApi($serviceData, $name);
            $parsedServices[$service->service] = $service;
        }

        $overallStatus = $attributes['overall_status'] ?? $attributes['status'] ?? null;
        if ($overallStatus !== null && ! is_string($overallStatus)) {
            throw NexusApiException::invalidResponse();
        }
        $overallStatus = is_string($overallStatus)
            ? self::normalizeStatus($overallStatus)
            : self::deriveOverallStatus($parsedServices);
        $serviceStatus = self::deriveOverallStatus($parsedServices);
        if ($serviceStatus === 'down') {
            $hasHealthyService = count(array_filter(
                $parsedServices,
                static fn (NexusHealthStatus $service): bool => $service->isHealthy(),
            )) > 0;
            $overallStatus = $hasHealthyService ? 'degraded' : 'down';
        } elseif ($overallStatus === 'healthy' && $serviceStatus !== 'healthy') {
            $overallStatus = $serviceStatus;
        }

        $timestamps = ['updated_at', 'timestamp', 'time'];
        $updatedAt = null;
        foreach ($timestamps as $field) {
            if (isset($attributes[$field])) {
                $value = $attributes[$field];
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $updatedAt = date('Y-m-d H:i:s', (int) $value);
                    break;
                }
                if (is_string($value) && strtotime($value) !== false) {
                    $updatedAt = $value;
                    break;
                }
            }
        }

        return new self(
            services: $parsedServices,
            updatedAt: $updatedAt,
            overallStatus: $overallStatus,
        );
    }

    private static function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'ok', 'healthy' => 'healthy',
            'degraded', 'partial' => 'degraded',
            'unhealthy', 'down', 'error' => 'down',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, NexusHealthStatus>  $services
     */
    private static function deriveOverallStatus(array $services): string
    {
        if ($services === []) {
            return 'unknown';
        }

        $degraded = false;
        foreach ($services as $service) {
            if ($service->status === 'down') {
                return 'down';
            }
            if ($service->status !== 'healthy') {
                $degraded = true;
            }
        }

        return $degraded ? 'degraded' : 'healthy';
    }

    public function isHealthy(): bool
    {
        return $this->overallStatus === 'healthy';
    }

    public function isDegraded(): bool
    {
        return $this->overallStatus === 'degraded';
    }

    public function unhealthyServices(): array
    {
        return array_filter(
            $this->services,
            fn (NexusHealthStatus $service) => $service->isDown(),
        );
    }
}
