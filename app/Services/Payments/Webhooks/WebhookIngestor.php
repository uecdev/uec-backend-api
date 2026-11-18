<?php

namespace App\Services\Payments\Webhooks;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Verifies, de-duplicates and queues inbound gateway webhooks (backend-handoff E.4).
 * The HTTP response is returned fast; processing happens on the "webhooks" queue.
 */
class WebhookIngestor
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{0: int, 1: string} [http status, outcome] */
    public function ingest(string $provider, Request $request): array
    {
        $gateway = $this->gateways->gateway($provider);

        if (! $gateway->verifyWebhookSignature($request)) {
            $this->audit->security('webhook_signature_invalid', "Rejected {$provider} webhook with an invalid signature.", [
                'provider' => $provider, 'ip' => $request->ip(),
            ]);

            return [401, 'invalid_signature'];
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return [400, 'invalid_payload'];
        }

        $key = $gateway->webhookEventKey($payload);
        $type = $gateway->webhookEventType($payload);

        // INSERT … ON CONFLICT DO NOTHING on (provider, event_key): duplicates are a no-op and,
        // unlike catching a unique violation, never abort an enclosing PostgreSQL transaction.
        $id = strtolower((string) Str::ulid());
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => $id,
            'provider' => $provider,
            'event_key' => mb_substr($key, 0, 191),
            'type' => $type,
            'reference' => $this->reference($provider, $payload),
            'payload' => json_encode($payload),
            'status' => 'received',
            'attempts' => 0,
            'ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return [200, 'duplicate']; // already received; idempotent no-op
        }

        ProcessWebhookEvent::dispatch($id)->onQueue('webhooks');

        return [200, 'queued'];
    }

    private function reference(string $provider, array $payload): ?string
    {
        $ref = $provider === 'paystack'
            ? ($payload['data']['reference'] ?? $payload['data']['transaction']['reference'] ?? null)
            : ($payload['eventData']['paymentReference'] ?? null);

        return is_string($ref) ? mb_substr($ref, 0, 100) : null;
    }
}
