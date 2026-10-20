<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusProjectApiKey
{
    public function __construct(
        public string $id,
        public string $name,
        public string $keyPrefix,
        public ?array $allowedExperts,
        public int $rateLimitPerMinute,
        public bool $active,
        public ?string $createdAt,
        public ?string $lastUsedAt,
        public ?string $startsAt,
        public ?string $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($attributes['name'] ?? null)
            || ! is_string($attributes['key_prefix'] ?? null)
            || ! is_bool($attributes['is_active'] ?? null)
            || ! is_int($attributes['rate_limit_per_min'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $allowedExperts = $attributes['allowed_experts'] ?? null;
        $timestamps = ['created_at', 'last_used_at', 'starts_at', 'expires_at'];

        if ($allowedExperts !== null && (! is_array($allowedExperts) || count(array_filter($allowedExperts, 'is_string')) !== count($allowedExperts))) {
            throw NexusApiException::invalidResponse();
        }

        foreach ($timestamps as $field) {
            if (
                isset($attributes[$field])
                && (! is_string($attributes[$field]) || strtotime($attributes[$field]) === false)
            ) {
                throw NexusApiException::invalidResponse();
            }
        }

        return new self(
            id: $attributes['id'],
            name: $attributes['name'],
            keyPrefix: $attributes['key_prefix'],
            allowedExperts: $allowedExperts,
            rateLimitPerMinute: $attributes['rate_limit_per_min'],
            active: $attributes['is_active'],
            createdAt: $attributes['created_at'] ?? null,
            lastUsedAt: $attributes['last_used_at'] ?? null,
            startsAt: $attributes['starts_at'] ?? null,
            expiresAt: $attributes['expires_at'] ?? null,
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return new \DateTimeImmutable($this->expiresAt) <= ($now ?? new \DateTimeImmutable);
    }

    public function status(?\DateTimeImmutable $now = null): string
    {
        if (! $this->active) {
            return 'revoked';
        }

        $now ??= new \DateTimeImmutable;
        if ($this->startsAt !== null && new \DateTimeImmutable($this->startsAt) > $now) {
            return 'scheduled';
        }

        return $this->isExpired($now) ? 'expired' : 'active';
    }

    public function connectionStatus(): string
    {
        return $this->lastUsedAt === null ? 'never_connected' : 'connected';
    }
}
