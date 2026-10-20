<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusConversationMessage
{
    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $expertAttribution
     */
    public function __construct(
        public string $id,
        public string $role,
        public string $content,
        public array $sources,
        public array $expertAttribution,
        public ?string $createdAt,
        public ?array $timings,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($attributes['role'] ?? null)
            || ! is_string($attributes['content'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $validRoles = ['user', 'assistant', 'system'];
        if (! in_array($attributes['role'], $validRoles, true)) {
            throw NexusApiException::invalidResponse();
        }

        $sources = $attributes['sources'] ?? [];
        if (! is_array($sources)) {
            throw NexusApiException::invalidResponse();
        }

        $expertAttribution = $attributes['expert_attribution'] ?? $attributes['experts'] ?? [];
        if (! is_array($expertAttribution)) {
            throw NexusApiException::invalidResponse();
        }

        $timestamps = ['created_at'];
        foreach ($timestamps as $field) {
            if (isset($attributes[$field]) && (! is_string($attributes[$field]) || strtotime($attributes[$field]) === false)) {
                throw NexusApiException::invalidResponse();
            }
        }

        $timings = $attributes['timings'] ?? null;
        if ($timings !== null && ! is_array($timings)) {
            throw NexusApiException::invalidResponse();
        }

        return new self(
            id: $attributes['id'],
            role: $attributes['role'],
            content: $attributes['content'],
            sources: $sources,
            expertAttribution: $expertAttribution,
            createdAt: $attributes['created_at'] ?? null,
            timings: $timings,
        );
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function hasSources(): bool
    {
        return count($this->sources) > 0;
    }

    public function hasExpertAttribution(): bool
    {
        return count($this->expertAttribution) > 0;
    }
}
