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
        public string $systemPrompt,
        public ?string $icon,
        public ?string $color,
        public array $keywords,
        public int $sortOrder,
        public bool $enabled,
        public bool $isSystem,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (! is_string($attributes['key'] ?? null) || ! is_string($attributes['name'] ?? null)) {
            throw NexusApiException::invalidResponse();
        }

        if (
            ! is_bool($attributes['is_system'] ?? null)
            || ! is_string($attributes['system_prompt'] ?? null)
            || ! is_int($attributes['sort_order'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $keywords = $attributes['keywords'] ?? [];

        // keywords может прийти как JSON-строка из PostgreSQL JSONB
        if (is_string($keywords) && trim($keywords) !== '') {
            $decoded = json_decode($keywords, true);
            if (is_array($decoded)) {
                $keywords = $decoded;
            } else {
                $keywords = [];
            }
        }

        if (! is_array($keywords)) {
            $keywords = [];
        }

        // Фильтруем только строки, игнорируя некорректные элементы
        $keywords = array_values(array_filter($keywords, 'is_string'));

        $enabled = $attributes['is_enabled'] ?? $attributes['enabled'] ?? true;

        // is_enabled может прийти как строка 'true'/'false' или число 0/1
        if (is_string($enabled)) {
            $enabled = in_array(strtolower($enabled), ['true', '1', 'yes'], true);
        } elseif (is_int($enabled)) {
            $enabled = $enabled !== 0;
        } elseif (! is_bool($enabled)) {
            $enabled = true;
        }

        return new self(
            key: $attributes['key'],
            name: $attributes['name'],
            description: is_string($attributes['description'] ?? null) ? $attributes['description'] : null,
            systemPrompt: $attributes['system_prompt'],
            icon: is_string($attributes['icon'] ?? null) ? $attributes['icon'] : null,
            color: is_string($attributes['color'] ?? null) ? $attributes['color'] : null,
            keywords: $keywords,
            sortOrder: $attributes['sort_order'],
            enabled: $enabled,
            isSystem: $attributes['is_system'],
        );
    }
}
