<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusProject
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($attributes['name'] ?? null)
            || (! is_string($attributes['description'] ?? null) && ($attributes['description'] ?? null) !== null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $metadata = $attributes['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw NexusApiException::invalidResponse();
        }

        return new self(
            id: $attributes['id'],
            name: $attributes['name'],
            description: $attributes['description'] ?? null,
            metadata: $metadata,
        );
    }
}
