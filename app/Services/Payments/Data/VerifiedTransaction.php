<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use Illuminate\Support\Carbon;

/**
 * A transaction as reported by the gateway, normalised across providers.
 */
final readonly class VerifiedTransaction
{
    /**
     * @param  array{token?: string, brand?: string, last4?: string, exp_month?: string, exp_year?: string, reusable?: bool, email?: string}|null  $authorization
     */
    public function __construct(
        public PaymentStatus $status,
        public string $reference,
        public int $amountKobo,
        public string $currency,
        public ?string $providerTransactionId = null,
        public ?string $channel = null,
        public ?array $authorization = null,
        public ?string $customerCode = null,
        public ?string $planCode = null,
        public ?Carbon $paidAt = null,
        public ?string $gatewayResponse = null,
        public array $raw = [],
    ) {}
}
