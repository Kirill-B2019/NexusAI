<?php

namespace App\Exceptions;

use RuntimeException;

class NexusApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $configurationError = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingConfiguration(string $name): self
    {
        return new self("Missing NEXUS AI configuration: {$name}", configurationError: true);
    }

    public static function invalidResponse(): self
    {
        return new self('NEXUS AI returned an unexpected experts response.');
    }
}
