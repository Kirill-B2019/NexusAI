<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusTask
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public string $status,
        public ?string $projectId,
        public ?string $assigneeExpert,
        public ?string $dueDate,
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

        $validStatuses = ['open', 'in_progress', 'done', 'cancelled'];
        if (! in_array($attributes['status'], $validStatuses, true)) {
            throw NexusApiException::invalidResponse();
        }

        $dueDate = $attributes['due_date'] ?? $attributes['dueDate'] ?? null;
        if ($dueDate !== null && (! is_string($dueDate) || strtotime($dueDate) === false)) {
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
            description: is_string($attributes['description'] ?? null) ? $attributes['description'] : null,
            status: $attributes['status'],
            projectId: is_string($attributes['project_id'] ?? null) ? $attributes['project_id'] : null,
            assigneeExpert: is_string($attributes['assignee_expert'] ?? null) ? $attributes['assignee_expert'] : null,
            dueDate: $dueDate,
            createdAt: $attributes['created_at'] ?? null,
            updatedAt: $attributes['updated_at'] ?? null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'Открыта',
            'in_progress' => 'В работе',
            'done' => 'Выполнена',
            'cancelled' => 'Отменена',
            default => $this->status,
        };
    }

    public function isOverdue(?string $now = null): bool
    {
        if ($this->dueDate === null || $this->status === 'done') {
            return false;
        }

        $due = new \DateTimeImmutable($this->dueDate);
        $check = $now !== null ? new \DateTimeImmutable($now) : new \DateTimeImmutable();

        return $due < $check;
    }
}
