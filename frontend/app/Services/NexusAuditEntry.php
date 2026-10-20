<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusAuditEntry
{
    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(
        public string $id,
        public string $action,
        public string $entityType,
        public ?string $entityId,
        public ?string $actor,
        public ?string $ipAddress,
        public array $changes,
        public ?string $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromApi(array $attributes): self
    {
        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($attributes['action'] ?? null)
            || ! is_string($attributes['entity_type'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $changes = $attributes['changes'] ?? $attributes['details'] ?? [];
        if (! is_array($changes)) {
            throw NexusApiException::invalidResponse();
        }

        $timestamps = ['created_at'];
        foreach ($timestamps as $field) {
            if (isset($attributes[$field]) && (! is_string($attributes[$field]) || strtotime($attributes[$field]) === false)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return new self(
            id: $attributes['id'],
            action: $attributes['action'],
            entityType: $attributes['entity_type'],
            entityId: is_string($attributes['entity_id'] ?? null) ? $attributes['entity_id'] : null,
            actor: is_string($attributes['actor'] ?? null) ? $attributes['actor'] : null,
            ipAddress: is_string($attributes['ip_address'] ?? null) ? $attributes['ip_address'] : null,
            changes: $changes,
            createdAt: $attributes['created_at'] ?? null,
        );
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'create' => 'Создание',
            'update' => 'Обновление',
            'delete' => 'Удаление',
            'login' => 'Вход',
            'logout' => 'Выход',
            default => $this->action,
        };
    }

    public function entityTypeLabel(): string
    {
        return match ($this->entityType) {
            'project' => 'Проект',
            'expert' => 'Эксперт',
            'user' => 'Пользователь',
            'document' => 'Документ',
            'conversation' => 'Беседа',
            'message' => 'Сообщение',
            'decision' => 'Решение',
            'task' => 'Задача',
            'api_key' => 'API ключ',
            default => $this->entityType,
        };
    }
}
