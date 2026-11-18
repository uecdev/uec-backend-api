<?php

namespace App\Services\Payments\Webhooks;

use App\Models\Payment;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\PaymentService;

class MonnifyWebhookHandler
{
    public function __construct(
        private readonly MonnifyGateway $gateway,
        private readonly PaymentService $payments,
    ) {}

    public function handle(array $payload): string
    {
        $data = (array) ($payload['eventData'] ?? []);

        return match ($payload['eventType'] ?? null) {
            'SUCCESSFUL_TRANSACTION', 'FAILED_TRANSACTION' => $this->transaction($data),
            'REFUND_COMPLETED' => $this->refund($data),
            default => 'ignored',
        };
    }

    private function transaction(array $data): string
    {
        $tx = $this->gateway->mapTransaction($data);
        $payment = Payment::query()->where('reference', $tx->reference)->first();
        if (! $payment) {
            return 'ignored';
        }
        $this->payments->apply($payment, $tx, 'webhook');

        return 'processed';
    }

    private function refund(array $data): string
    {
        $txRef = $data['transactionReference'] ?? null;
        $payment = $txRef ? Payment::query()->where('provider', 'monnify')->where('provider_transaction_id', $txRef)->first() : null;
        if (! $payment) {
            return 'ignored';
        }
        $this->payments->refund($payment, 'webhook', ['refund_reference' => $data['refundReference'] ?? null, 'amount' => $data['refundAmount'] ?? null]);

        return 'processed';
    }
}
