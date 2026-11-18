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

/**
 * Paystack (https://paystack.com/docs/api). Hosted checkout via
 * /transaction/initialize, subscriptions via plan codes, HMAC-SHA512 webhooks.
 */
class PaystackGateway implements PaymentGateway
{
    use LogsIntegrationCalls;

    public function name(): string
    {
        return 'paystack';
    }

    protected function integrationName(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('paystack');
        }

        return $this->http((int) config('services.paystack.timeout', 20))
            ->baseUrl(rtrim((string) config('services.paystack.base_url'), '/'))
            ->withToken((string) config('services.paystack.secret_key'));
    }

    public function initialize(Payment $payment, Plan $plan, User $user, string $gatewayCallbackUrl): CheckoutSession
    {
        $payload = [
            'email' => $user->billingEmail(),
            'amount' => $payment->amount_kobo,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $gatewayCallbackUrl,
            'channels' => ['card'], // recurring billing needs a reusable card authorization
            'metadata' => [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'plan_id' => $plan->id,
                'cancel_action' => $gatewayCallbackUrl.(str_contains($gatewayCallbackUrl, '?') ? '&' : '?').'reference='.$payment->reference,
            ],
        ];
        if ($plan->paystack_plan_code) {
            // Paystack then creates the recurring subscription automatically after the first charge.
            $payload['plan'] = $plan->paystack_plan_code;
        }

        $response = $this->call('transaction.initialize', 'POST', '/transaction/initialize',
            fn () => $this->client()->post('/transaction/initialize', $payload), $payment->reference);

        $data = $this->dataOrFail($response, 'Could not initialise checkout.');

        return new CheckoutSession(
            authorizationUrl: (string) $data['authorization_url'],
            providerReference: $data['access_code'] ?? null,
            raw: ['access_code' => $data['access_code'] ?? null],
        );
    }

    public function verify(Payment $payment): VerifiedTransaction
    {
        $path = '/transaction/verify/'.rawurlencode($payment->reference);
        $response = $this->call('transaction.verify', 'GET', '/transaction/verify/{reference}',
            fn () => $this->client()->get($path), $payment->reference);

        if ($response->status() === 404 || ($response->json('status') === false && $response->status() === 400)) {
            // Paystack has no record yet: the user never reached the payment page.
            return new VerifiedTransaction(PaymentStatus::Pending, $payment->reference, 0, $payment->currency);
        }

        return $this->mapTransaction($this->dataOrFail($response, 'Could not verify transaction.'));
    }

    /** Normalise a Paystack transaction object (verify response or charge.success data). */
    public function mapTransaction(array $data): VerifiedTransaction
    {
        $status = match ($data['status'] ?? null) {
            'success' => PaymentStatus::Success,
            'failed' => PaymentStatus::Failed,
            'abandoned' => PaymentStatus::Abandoned,
            'reversed' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };

        $auth = $data['authorization'] ?? null;
        $plan = $data['plan'] ?? null;

        return new VerifiedTransaction(
            status: $status,
            reference: (string) ($data['reference'] ?? ''),
            amountKobo: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            providerTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            channel: $data['channel'] ?? null,
            authorization: is_array($auth) && ! empty($auth['authorization_code']) ? [
                'token' => $auth['authorization_code'],
                'brand' => $auth['brand'] ?? $auth['card_type'] ?? null,
                'last4' => $auth['last4'] ?? null,
                'exp_month' => $auth['exp_month'] ?? null,
                'exp_year' => $auth['exp_year'] ?? null,
                'reusable' => (bool) ($auth['reusable'] ?? false),
                'email' => $data['customer']['email'] ?? null,
            ] : null,
            customerCode: $data['customer']['customer_code'] ?? null,
            planCode: is_array($plan) ? ($plan['plan_code'] ?? null) : (is_string($plan) && $plan !== '' ? $plan : null),
            paidAt: ! empty($data['paid_at'] ?? $data['paidAt'] ?? null) ? Carbon::parse($data['paid_at'] ?? $data['paidAt']) : null,
            gatewayResponse: isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 255) : null,
            raw: $this->sanitize($data),
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) config('services.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature', '');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $allowedIps = config('services.paystack.webhook_ips', []);
        if ($allowedIps && ! in_array($request->ip(), $allowedIps, true)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function webhookEventKey(array $payload): string
    {
        $event = (string) ($payload['event'] ?? 'unknown');
        $data = $payload['data'] ?? [];
        $id = $data['id'] ?? $data['subscription_code'] ?? $data['invoice_code'] ?? $data['reference'] ?? null;

        return $id !== null ? "{$event}:{$id}" : $event.':'.hash('sha256', json_encode($payload));
    }

    public function webhookEventType(array $payload): ?string
    {
        return $payload['event'] ?? null;
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        if (! $subscription->provider_subscription_code) {
            // One-off authorization billing: renewals simply stop being charged.
            return true;
        }

        $token = $subscription->provider_email_token ?: $this->fetchEmailToken($subscription->provider_subscription_code);
        if (! $token) {
            return false;
        }

        $response = $this->call('subscription.disable', 'POST', '/subscription/disable',
            fn () => $this->client()->post('/subscription/disable', [
                'code' => $subscription->provider_subscription_code,
                'token' => $token,
            ]), $subscription->id);

        // Already-disabled subscriptions return 400 with "Subscription with code not found or already inactive".
        return $response->successful() || str_contains(strtolower((string) $response->json('message')), 'already inactive');
    }

    public function scheduleRenewal(Subscription $subscription, Plan $plan): void
    {
        $authorization = $subscription->authorization['token'] ?? null;
        if (! $plan->paystack_plan_code || ! $subscription->provider_customer_code || ! $authorization) {
            // No Paystack plan/customer/authorization: renewals fall back to charge_authorization (chargeRenewal).
            $subscription->forceFill(['provider_subscription_code' => null, 'provider_email_token' => null])->save();

            return;
        }

        if ($subscription->provider_subscription_code) {
            $this->cancelSubscription($subscription);
        }

        $response = $this->call('subscription.create', 'POST', '/subscription',
            fn () => $this->client()->post('/subscription', [
                'customer' => $subscription->provider_customer_code,
                'plan' => $plan->paystack_plan_code,
                'authorization' => $authorization,
                'start_date' => $subscription->current_period_end->toIso8601String(),
            ]), $subscription->id);

        $data = $this->dataOrFail($response, 'Could not schedule the renewal.');
        $subscription->forceFill([
            'provider_subscription_code' => $data['subscription_code'] ?? null,
            'provider_email_token' => $data['email_token'] ?? null,
            'gateway_synced_at' => now(),
        ])->save();
    }

    public function chargeRenewal(Subscription $subscription, Payment $payment): ?VerifiedTransaction
    {
        if ($subscription->provider_subscription_code) {
            return null; // Paystack runs the schedule and reports renewals via webhooks.
        }

        $authorization = $subscription->authorization['token'] ?? null;
        if (! $authorization) {
            throw new IntegrationException('paystack', 'No reusable authorization stored for renewal.');
        }

        $response = $this->call('transaction.charge_authorization', 'POST', '/transaction/charge_authorization',
            fn () => $this->client()->post('/transaction/charge_authorization', [
                'email' => $subscription->authorization['email'] ?? $subscription->user->billingEmail(),
                'amount' => $payment->amount_kobo,
                'currency' => $payment->currency,
                'authorization_code' => $authorization,
                'reference' => $payment->reference,
                'metadata' => ['subscription_id' => $subscription->id, 'payment_id' => $payment->id],
            ]), $payment->reference);

        return $this->mapTransaction($this->dataOrFail($response, 'Renewal charge failed.'));
    }

    /** Create the plan on Paystack and return its plan code (admin "sync plan" action). */
    public function createPlan(Plan $plan): string
    {
        $response = $this->call('plan.create', 'POST', '/plan', fn () => $this->client()->post('/plan', [
            'name' => 'UEC '.$plan->name,
            'interval' => $plan->paystackInterval(),
            'amount' => $plan->amount_kobo,
            'currency' => $plan->currency,
        ]), $plan->id);

        return (string) $this->dataOrFail($response, 'Could not create plan.')['plan_code'];
    }

    public function ping(): bool
    {
        $response = $this->call('ping', 'GET', '/transaction', fn () => $this->client()->get('/transaction', ['perPage' => 1]));

        return $response->successful();
    }

    private function fetchEmailToken(string $code): ?string
    {
        $response = $this->call('subscription.fetch', 'GET', '/subscription/{code}',
            fn () => $this->client()->get('/subscription/'.rawurlencode($code)));

        return $response->successful() ? ($response->json('data.email_token') ?: null) : null;
    }

    private function dataOrFail(Response $response, string $message): array
    {
        if (! $response->successful() || $response->json('status') !== true) {
            throw new IntegrationException('paystack', $message.' '.($response->json('message') ?? ''), $response->status());
        }

        return (array) $response->json('data', []);
    }

    /** Keep only non-sensitive fields of a transaction for the audit trail. */
    private function sanitize(array $data): array
    {
        $auth = $data['authorization'] ?? [];

        return array_filter([
            'id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? null,
            'paid_at' => $data['paid_at'] ?? $data['paidAt'] ?? null,
            'customer_code' => $data['customer']['customer_code'] ?? null,
            'plan' => is_array($data['plan'] ?? null) ? ($data['plan']['plan_code'] ?? null) : ($data['plan'] ?? null),
            'card' => $auth ? array_filter([
                'brand' => $auth['brand'] ?? null,
                'last4' => $auth['last4'] ?? null,
                'bank' => $auth['bank'] ?? null,
            ]) : null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
