<?php

namespace App\Services\Payments\Data;

use Illuminate\Support\Carbon;

final readonly class CheckoutSession
{
    public function __construct(
        public string $authorizationUrl,
        public ?string $providerReference = null,
        public ?Carbon $expiresAt = null,
        public array $raw = [],
    ) {}
}
