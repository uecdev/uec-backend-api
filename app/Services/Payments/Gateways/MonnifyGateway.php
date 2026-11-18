<?php

namespace App\Services\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\VerifiedTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Monnify (https://developers.monnify.com). Hosted checkout via init-transaction;
 * renewals are charged by our scheduler using the reusable card token
 * (charge-card-token), since Monnify does not run plan schedules for us.
 * Monnify amounts are in Naira (decimal); we convert from kobo at the boundary.
 */
class MonnifyGateway implements PaymentGateway
{
    use LogsIntegrationCalls;

    public function name(): string
    {
        return 'monnify';
    }

    protected function integrationName(): string
    {
        return 'monnify';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.monnify.api_key'))
            && filled(config('services.monnify.secret_key'))
            && filled(config('services.monnify.contract_code'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.monnify.base_url'), '/');
    }

    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('monnify');
        }

        $cacheKey = 'monnify:token:'.sha1((string) config('services.monnify.api_key'));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->call('auth.login', 'POST', '/api/v1/auth/login', fn () => $this->http((int) config('services.monnify.timeout', 20))
            ->withBasicAuth((string) config('services.monnify.api_key'), (string) config('services.monnify.secret_key'))
            ->post($this->baseUrl().'/api/v1/auth/login'));

        $body = $this->bodyOrFail($response, 'Authentication failed.');
        $token = (string) ($body['accessToken'] ?? '');
        $ttl = max(60, (int) ($body['expiresIn'] ?? 3000) - 120);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function client(): PendingRequest
    {
        return $this->http((int) config('services.monnify.timeout', 20))
            ->baseUrl($this->baseUrl())
            ->withToken($this->accessToken());
    }

    public function initialize(Payment $payment, Plan $plan, User $user, string $gatewayCallbackUrl): CheckoutSession
    {
        $response = $this->call('transactions.init', 'POST', '/api/v1/merchant/transactions/init-transaction',
            fn () => $this->client()->post('/api/v1/merchant/transactions/init-transaction', [
                'amount' => $payment->amount_kobo / 100,
                'customerName' => $user->name ?: 'UEC Member',
                'customerEmail' => $user->billingEmail(),
                'paymentReference' => $payment->reference,
                'paymentDescription' => 'UEC '.$plan->name.' subscription',
                'currencyCode' => $payment->currency,
                'contractCode' => (string) config('services.monnify.contract_code'),
                'redirectUrl' => $gatewayCallbackUrl,
                'paymentMethods' => ['CARD'], // a reusable card token is required for renewals
                'metaData' => ['user_id' => $user->id, 'plan_id' => $plan->id],
            ]), $payment->reference);

        $body = $this->bodyOrFail($response, 'Could not initialise checkout.');

        return new CheckoutSession(
            authorizationUrl: (string) $body['checkoutUrl'],
            providerReference: $body['transactionReference'] ?? null,
            raw: ['transactionReference' => $body['transactionReference'] ?? null],
        );
    }

    public function verify(Payment $payment): VerifiedTransaction
    {
        $response = $this->call('transactions.query', 'GET', '/api/v2/merchant/transactions/query',
            fn () => $this->client()->get('/api/v2/merchant/transactions/query', ['paymentReference' => $payment->reference]),
            $payment->reference);

        if ($response->status() === 404) {
            return new VerifiedTransaction(PaymentStatus::Pending, $payment->reference, 0, $payment->currency);
        }

        return $this->mapTransaction($this->bodyOrFail($response, 'Could not verify transaction.'));
    }

    /** Normalise a Monnify transaction (query response body or webhook eventData). */
    public function mapTransaction(array $data): VerifiedTransaction
    {
        $status = match (strtoupper((string) ($data['paymentStatus'] ?? ''))) {
            'PAID', 'OVERPAID' => PaymentStatus::Success,
            'FAILED' => PaymentStatus::Failed,
            'ABANDONED', 'EXPIRED', 'CANCELLED' => PaymentStatus::Abandoned,
            'REVERSED' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };

        $card = $data['cardDetails'] ?? null;
        $amount = $data['amountPaid'] ?? $data['amount'] ?? 0;
        $paidOn = $data['paidOn'] ?? null;

        return new VerifiedTransaction(
            status: $status,
            reference: (string) ($data['paymentReference'] ?? ''),
            amountKobo: (int) round(((float) $amount) * 100),
            currency: (string) ($data['currencyCode'] ?? $data['currency'] ?? 'NGN'),
            providerTransactionId: $data['transactionReference'] ?? null,
            channel: isset($data['paymentMethod']) ? strtolower((string) $data['paymentMethod']) : null,
            authorization: is_array($card) && ! empty($card['cardToken']) ? [
                'token' => $card['cardToken'],
                'brand' => $card['cardType'] ?? null,
                'last4' => $card['last4'] ?? (isset($card['maskedPan']) ? substr((string) $card['maskedPan'], -4) : null),
                'exp_month' => $card['expMonth'] ?? null,
                'exp_year' => $card['expYear'] ?? null,
                'reusable' => (bool) ($card['reusable'] ?? $card['supportsTokenization'] ?? true),
                'email' => $data['customer']['email'] ?? null,
            ] : null,
            customerCode: $data['customer']['email'] ?? null,
            paidAt: $paidOn ? Carbon::parse($paidOn, config('uec.timezone'))->utc() : null,
            gatewayResponse: isset($data['paymentDescription']) ? mb_substr((string) $data['paymentDescription'], 0, 255) : null,
            raw: array_filter([
                'transactionReference' => $data['transactionReference'] ?? null,
                'paymentReference' => $data['paymentReference'] ?? null,
                'paymentStatus' => $data['paymentStatus'] ?? null,
                'amountPaid' => $data['amountPaid'] ?? null,
                'paymentMethod' => $data['paymentMethod'] ?? null,
                'paidOn' => $paidOn,
                'card' => is_array($card) ? array_filter(['type' => $card['cardType'] ?? null, 'last4' => $card['last4'] ?? null]) : null,
            ], fn ($v) => $v !== null && $v !== []),
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) config('services.monnify.secret_key');
        $signature = (string) $request->header('monnify-signature', '');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function webhookEventKey(array $payload): string
    {
        $type = (string) ($payload['eventType'] ?? 'unknown');
        $data = $payload['eventData'] ?? [];
        $id = $data['transactionReference'] ?? $data['paymentReference'] ?? $data['refundReference'] ?? null;

        return $id !== null ? "{$type}:{$id}" : $type.':'.hash('sha256', json_encode($payload));
    }

    public function webhookEventType(array $payload): ?string
    {
        return $payload['eventType'] ?? null;
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        return true; // renewals are driven by our scheduler; cancelling simply stops future charges.
    }

    public function scheduleRenewal(Subscription $subscription, Plan $plan): void
    {
        // Nothing to do at the gateway; the scheduler charges the stored card token for $plan at period end.
    }

    public function chargeRenewal(Subscription $subscription, Payment $payment): ?VerifiedTransaction
    {
        $token = $subscription->authorization['token'] ?? null;
        if (! $token) {
            throw new IntegrationException('monnify', 'No reusable card token stored for renewal.');
        }
        $user = $subscription->user;

        $response = $this->call('cards.charge_token', 'POST', '/api/v1/merchant/cards/charge-card-token',
            fn () => $this->client()->post('/api/v1/merchant/cards/charge-card-token', [
                'cardToken' => $token,
                'amount' => $payment->amount_kobo / 100,
                'customerName' => $user->name ?: 'UEC Member',
                'customerEmail' => $subscription->authorization['email'] ?? $user->billingEmail(),
                'paymentReference' => $payment->reference,
                'paymentDescription' => 'UEC subscription renewal',
                'currencyCode' => $payment->currency,
                'contractCode' => (string) config('services.monnify.contract_code'),
                'apiKey' => (string) config('services.monnify.api_key'),
                'metaData' => ['subscription_id' => $subscription->id],
            ]), $payment->reference);

        $body = $this->bodyOrFail($response, 'Renewal charge failed.');
        $body['paymentReference'] ??= $payment->reference;

        return $this->mapTransaction($body);
    }

    public function ping(): bool
    {
        Cache::forget('monnify:token:'.sha1((string) config('services.monnify.api_key')));

        return $this->accessToken() !== '';
    }

    private function bodyOrFail(Response $response, string $message): array
    {
        if (! $response->successful() || $response->json('requestSuccessful') !== true) {
            throw new IntegrationException('monnify', $message.' '.($response->json('responseMessage') ?? ''), $response->status());
        }

        return (array) $response->json('responseBody', []);
    }
}
