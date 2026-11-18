<?php

namespace App\Integrations;

use RuntimeException;
use Throwable;

class IntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $integration,
        string $message,
        public readonly ?int $statusCode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$integration}] {$message}", 0, $previous);
    }

    public static function notConfigured(string $integration): self
    {
        return new self($integration, 'Integration is not configured.');
    }
}
