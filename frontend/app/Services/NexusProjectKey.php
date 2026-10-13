<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusProjectKey
{
    public function __construct(
        public string $id,
        private string $secret,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (! is_string($attributes['id'] ?? null) || ! is_string($attributes['key'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        return new self($attributes['id'], $attributes['key']);
    }

    public function revealForServerStorage(): string
    {
        return $this->secret;
    }
}
