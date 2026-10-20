<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusConversation
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $projectId,
        public ?string $createdAt,
        public ?string $lastMessageAt,
        public ?int $messageCount,
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
        ) {
            throw NexusApiException::invalidResponse();
        }

        $messageCount = $attributes['message_count'] ?? $attributes['messageCount'] ?? null;
        if ($messageCount !== null && ! is_int($messageCount)) {
            throw NexusApiException::invalidResponse();
        }

        $timestamps = ['created_at', 'last_message_at'];
        foreach ($timestamps as $field) {
            if (isset($attributes[$field]) && (! is_string($attributes[$field]) || strtotime($attributes[$field]) === false)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return new self(
            id: $attributes['id'],
            title: $attributes['title'],
            projectId: is_string($attributes['project_id'] ?? null) ? $attributes['project_id'] : null,
            createdAt: $attributes['created_at'] ?? null,
            lastMessageAt: $attributes['last_message_at'] ?? null,
            messageCount: $messageCount,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    public function formattedTitle(): string
    {
        if ($this->title !== '' && $this->title !== 'Untitled') {
            return $this->title;
        }

        return 'Беседа ' . $this->id;
    }
}
