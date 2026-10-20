<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $content,
        public string $status,
        public ?string $projectId,
        public ?string $createdAt,
        public ?string $updatedAt,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($attributes['title'] ?? null)
            || ! is_string($attributes['status'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $validStatuses = ['active', 'superseded', 'archived'];
        if (! in_array($attributes['status'], $validStatuses, true)) {
            throw NexusApiException::invalidResponse();
        }

        $timestamps = ['created_at', 'updated_at'];
        foreach ($timestamps as $field) {
            if (isset($attributes[$field]) && (! is_string($attributes[$field]) || strtotime($attributes[$field]) === false)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return new self(
            id: $attributes['id'],
            title: $attributes['title'],
            content: is_string($attributes['content'] ?? null) ? $attributes['content'] : null,
            status: $attributes['status'],
            projectId: is_string($attributes['project_id'] ?? null) ? $attributes['project_id'] : null,
            createdAt: $attributes['created_at'] ?? null,
            updatedAt: $attributes['updated_at'] ?? null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Активно',
            'superseded' => 'Заменено',
            'archived' => 'Архив',
            default => $this->status,
        };
    }
}
