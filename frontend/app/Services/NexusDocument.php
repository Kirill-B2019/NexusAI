<?php

namespace App\Services;

use App\Exceptions\NexusApiException;

final readonly class NexusDocument
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?int $fileSize,
        public ?string $projectId,
        public ?int $chunkCount,
        public ?string $uploadedAt,
        public ?string $updatedAt,
        public ?string $error,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromApi(array $attributes): self
    {
        $name = $attributes['name'] ?? $attributes['original_filename'] ?? $attributes['filename'] ?? null;

        if (
            ! is_string($attributes['id'] ?? null)
            || ! is_string($name)
            || ! is_string($attributes['status'] ?? null)
        ) {
            throw NexusApiException::invalidResponse();
        }

        $validStatuses = ['pending', 'processing', 'ready', 'failed'];
        if (! in_array($attributes['status'], $validStatuses, true)) {
            throw NexusApiException::invalidResponse();
        }

        $chunkCount = $attributes['chunks_count'] ?? $attributes['chunk_count'] ?? $attributes['chunkCount'] ?? null;
        if ($chunkCount !== null && ! is_int($chunkCount)) {
            throw NexusApiException::invalidResponse();
        }

        $fileSize = $attributes['file_size'] ?? null;
        if ($fileSize !== null && (! is_int($fileSize) || $fileSize < 0)) {
            throw NexusApiException::invalidResponse();
        }

        $uploadedAt = $attributes['uploaded_at'] ?? $attributes['created_at'] ?? null;
        $updatedAt = $attributes['updated_at'] ?? $attributes['processed_at'] ?? null;
        foreach ([$uploadedAt, $updatedAt] as $timestamp) {
            if ($timestamp !== null && (! is_string($timestamp) || strtotime($timestamp) === false)) {
                throw NexusApiException::invalidResponse();
            }
        }

        return new self(
            id: $attributes['id'],
            name: $name,
            status: $attributes['status'],
            fileSize: $fileSize,
            projectId: is_string($attributes['project_id'] ?? null) ? $attributes['project_id'] : null,
            chunkCount: $chunkCount,
            uploadedAt: $uploadedAt,
            updatedAt: $updatedAt,
            error: is_string($attributes['error'] ?? null) ? $attributes['error'] : null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function hasError(): bool
    {
        return $this->status === 'failed' || $this->error !== null;
    }
}
