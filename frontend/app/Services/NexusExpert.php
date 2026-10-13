<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusExpert
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public ?string $icon,
        public ?string $color,
        public array $keywords,
        public bool $enabled,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (! is_string($attributes['key'] ?? null) || ! is_string($attributes['name'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        $keywords = $attributes['keywords'] ?? [];
        $enabled = $attributes['is_enabled'] ?? $attributes['enabled'] ?? true;

        if (! is_array($keywords) || count(array_filter($keywords, 'is_string')) !== count($keywords) || ! is_bool($enabled)) {
            throw NexusApiException::invalidResponse();
        }

        return new self(
            key: $attributes['key'],
            name: $attributes['name'],
            description: is_string($attributes['description'] ?? null) ? $attributes['description'] : null,
            icon: is_string($attributes['icon'] ?? null) ? $attributes['icon'] : null,
            color: is_string($attributes['color'] ?? null) ? $attributes['color'] : null,
            keywords: array_values($keywords),
            enabled: $enabled,
        );
    }
}
