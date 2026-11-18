<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A domain error rendered with the contract's error shape (backend-handoff C.1):
 * { "message": string, "code"?: string, "errors"?: {...}, ...extra }
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  additional top-level keys (e.g. "verification")
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $errorCode = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function premiumRequired(): self
    {
        return new self(403, __('api.premium_required'), 'PREMIUM_REQUIRED');
    }

    public static function notFound(?string $message = null): self
    {
        return new self(404, $message ?? __('api.not_found'), 'NOT_FOUND');
    }

    public static function validation(string $field, string $message, int $status = 422): self
    {
        return new self($status, $message, null, ['errors' => [$field => [$message]]]);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage()];
        if ($this->errorCode !== null) {
            $body['code'] = $this->errorCode;
        }

        return response()->json(array_merge($body, $this->extra), $this->status, $this->headers);
    }
}
