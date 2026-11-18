<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentSucceeded;
use App\Integrations\IntegrationException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\Data\VerifiedTransaction;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Checkout, verification and the single idempotent path through which every
 * gateway result (verify polling, webhooks, scheduler renewals) is applied.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    public function newReference(): string
    {
        return config('uec.subscriptions.payment_reference_prefix').strtolower((string) Str::ulid());
    }

    /** POST /me/subscription/checkout (backend-handoff E.3). */
    public function checkout(User $user, Plan $plan, string $provider, string $appCallbackUrl): Payment
    {
        if (! $this->gateways->isAvailable($provider)) {
            throw ApiException::validation('provider', __('api.provider_unavailable'));
        }

        $current = $this->subscriptions->current($user);
        if ($current?->grantsAccess()) {
            throw new ApiException(409, __('api.already_subscribed'), 'ALREADY_SUBSCRIBED');
        }

        // Amount always comes from the plan in the database, never from the client.
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'reference' => $this->newReference(),
            'provider' => $provider,
            'purpose' => 'subscription',
            'amount_kobo' => $plan->amount_kobo,
            'currency' => $plan->currency,
            'status' => PaymentStatus::Pending,
            'app_callback_url' => $appCallbackUrl,
            'expires_at' => now()->addMinutes(config('uec.subscriptions.checkout_expiry_minutes')),
        ]);

        try {
            $session = $this->gateways->gateway($provider)->initialize($payment, $plan, $user, $this->gatewayCallbackUrl());
        } catch (IntegrationException $e) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'gateway_response' => 'Initialisation failed',
            ])->save();
            Log::error('payment.initialize_failed', ['reference' => $payment->reference, 'provider' => $provider, 'error' => $e->getMessage()]);

            throw new ApiException(502, __('api.payment_provider_error'), 'PAYMENT_PROVIDER_ERROR');
        }

        $payment->forceFill([
            'authorization_url' => $session->authorizationUrl,
            'provider_transaction_id' => $session->providerReference,
            'expires_at' => $session->expiresAt ?? $payment->expires_at,
            'metadata' => $session->raw ?: null,
        ])->save();

        $this->audit->log('payment.checkout_started', $payment, null, ['plan_id' => $plan->id, 'provider' => $provider], $user);

        return $payment;
    }

    /** The https URL the gateway redirects the browser to; it bounces to the app scheme. */
    public function gatewayCallbackUrl(): string
    {
        return rtrim((string) config('uec.urls.api'), '/').'/api/v1/payments/callback';
    }

    /**
     * POST /me/subscription/verify — idempotent; the app polls it while pending.
     * Falls back to the gateway's verify API when the webhook has not arrived yet.
     */
    public function verifyForUser(User $user, string $reference): Payment
    {
        $payment = Payment::query()->where('reference', $reference)->where('user_id', $user->id)->first();
        if (! $payment) {
            throw ApiException::notFound(__('api.payment_not_found'));
        }

        if ($payment->status === PaymentStatus::Pending || $payment->status === PaymentStatus::Abandoned) {
            $this->refreshFromGateway($payment);
        }

        return $payment->refresh();
    }

    public function refreshFromGateway(Payment $payment): Payment
    {
        // Avoid hammering the gateway when the app polls every few seconds.
        if ($payment->last_verified_at && $payment->last_verified_at->gt(now()->subSeconds(2))) {
            return $payment;
        }

        try {
            $tx = $this->gateways->gateway($payment->provider)->verify($payment);
        } catch (IntegrationException $e) {
            Log::warning('payment.verify_failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
            $payment->forceFill(['last_verified_at' => now()])->save();

            return $payment;
        }

        $this->apply($payment, $tx, 'verify');

        return $payment->refresh();
    }

    /**
     * Apply a gateway-reported transaction to our payment record. Idempotent and
     * serialised per payment with a row lock, so duplicate webhooks, concurrent
     * verify polls and retries cannot double-activate a subscription.
     */
    public function apply(Payment $payment, VerifiedTransaction $tx, string $source): Payment
    {
        return DB::transaction(function () use ($payment, $tx, $source) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->forceFill(['last_verified_at' => now()]);

            if ($tx->status === PaymentStatus::Refunded) {
                return $this->refund($locked, $source, $tx->raw);
            }

            if ($locked->status === PaymentStatus::Success || $locked->status === PaymentStatus::Refunded) {
                $locked->save();

                return $locked; // already final
            }

            switch ($tx->status) {
                case PaymentStatus::Success:
                    if (! $this->matches($locked, $tx)) {
                        $this->audit->security('payment_mismatch', 'Gateway transaction does not match the stored payment; kept pending.', [
                            'reference' => $locked->reference,
                            'expected_amount' => $locked->amount_kobo,
                            'reported_amount' => $tx->amountKobo,
                            'expected_currency' => $locked->currency,
                            'reported_currency' => $tx->currency,
                            'reported_reference' => $tx->reference,
                            'source' => $source,
                        ], $locked);
                        $locked->save();

                        return $locked;
                    }

                    $locked->forceFill([
                        'status' => PaymentStatus::Success,
                        'paid_at' => $tx->paidAt ?? now(),
                        'channel' => $tx->channel,
                        'provider_transaction_id' => $tx->providerTransactionId ?? $locked->provider_transaction_id,
                        'gateway_response' => $tx->gatewayResponse,
                        'raw' => $tx->raw,
                    ])->save();

                    if ($locked->purpose === 'renewal' && $locked->subscription_id) {
                        $this->subscriptions->renew($locked->subscription, $locked, $tx);
                    } else {
                        $this->subscriptions->activateFromPayment($locked, $tx);
                    }

                    $this->audit->log('payment.succeeded', $locked, "Confirmed via {$source}", ['provider' => $locked->provider, 'amount' => $locked->amount_kobo], actorType: 'gateway');
                    PaymentSucceeded::dispatch($locked);
                    break;

                case PaymentStatus::Failed:
                    $locked->forceFill([
                        'status' => PaymentStatus::Failed,
                        'failed_at' => now(),
                        'gateway_response' => $tx->gatewayResponse,
                        'raw' => $tx->raw ?: $locked->raw,
                    ])->save();
                    if ($locked->purpose === 'renewal' && $locked->subscription) {
                        $this->subscriptions->markPastDue($locked->subscription);
                    }
                    break;

                case PaymentStatus::Abandoned:
                    $locked->forceFill(['status' => PaymentStatus::Abandoned, 'gateway_response' => $tx->gatewayResponse])->save();
                    break;

                default:
                    // Still pending; if the checkout window has passed with no record at the gateway, call it abandoned.
                    if ($locked->expires_at && $locked->expires_at->isPast()) {
                        $locked->forceFill(['status' => PaymentStatus::Abandoned])->save();
                    } else {
                        $locked->save();
                    }
            }

            return $locked;
        });
    }

    /** Refund / chargeback: mark refunded and revoke entitlements immediately (E.8). */
    public function refund(Payment $payment, string $source, array $raw = []): Payment
    {
        if ($payment->status === PaymentStatus::Refunded) {
            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'refunded_at' => now(),
            'raw' => array_merge((array) $payment->raw, ['refund' => $raw]),
        ])->save();

        if ($payment->subscription && $payment->subscription->status !== SubscriptionStatus::Expired) {
            $this->tryCancelAtGateway($payment->subscription);
            $this->subscriptions->expire($payment->subscription, 'Payment refunded or charged back.');
        }

        $this->audit->log('payment.refunded', $payment, "Refund recorded via {$source}", actorType: 'gateway');

        return $payment;
    }

    /**
     * Record and apply a renewal charge that the gateway initiated on its own schedule
     * (e.g. Paystack subscription charge.success with a Paystack-generated reference).
     */
    public function recordGatewayRenewal(Subscription $subscription, VerifiedTransaction $tx): Payment
    {
        $planId = $subscription->pending_plan_id && $tx->amountKobo === $subscription->pendingPlan?->amount_kobo
            ? $subscription->pending_plan_id
            : $subscription->plan_id;

        $payment = Payment::query()->firstOrCreate(['reference' => $tx->reference], [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $planId,
            'provider' => $subscription->provider,
            'purpose' => 'renewal',
            'amount_kobo' => $tx->amountKobo,
            'currency' => $tx->currency,
            'status' => PaymentStatus::Pending,
        ]);

        return $this->apply($payment, $tx, 'webhook');
    }

    /**
     * Charge renewals that are due for gateways where we drive the schedule
     * (Monnify, or Paystack without a gateway subscription). Scheduler entry point.
     */
    public function chargeDueRenewals(): int
    {
        $count = 0;
        Subscription::query()
            ->status(SubscriptionStatus::Active)
            ->where('current_period_end', '<=', now())
            ->whereNotNull('authorization')
            ->where(fn ($q) => $q->where('provider', 'monnify')->orWhere(fn ($p) => $p->where('provider', 'paystack')->whereNull('provider_subscription_code')))
            ->with(['plan', 'pendingPlan', 'user'])
            ->each(function (Subscription $subscription) use (&$count) {
                // One renewal attempt per period.
                $alreadyAttempted = Payment::query()->where('subscription_id', $subscription->id)
                    ->where('purpose', 'renewal')
                    ->where('created_at', '>=', $subscription->current_period_end)
                    ->exists();
                if ($alreadyAttempted) {
                    return;
                }

                $plan = $subscription->pendingPlan ?? $subscription->plan;
                $payment = Payment::query()->create([
                    'user_id' => $subscription->user_id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                    'reference' => $this->newReference(),
                    'provider' => $subscription->provider,
                    'purpose' => 'renewal',
                    'amount_kobo' => $plan->amount_kobo,
                    'currency' => $plan->currency,
                    'status' => PaymentStatus::Pending,
                ]);

                try {
                    $tx = $this->gateways->gateway($subscription->provider)->chargeRenewal($subscription, $payment);
                    if ($tx) {
                        $this->apply($payment, $tx, 'renewal_charge');
                    }
                } catch (IntegrationException $e) {
                    $payment->forceFill(['status' => PaymentStatus::Failed, 'failed_at' => now(), 'gateway_response' => mb_substr($e->getMessage(), 0, 255)])->save();
                    $this->subscriptions->markPastDue($subscription);
                }
                $count++;
            });

        return $count;
    }

    private function matches(Payment $payment, VerifiedTransaction $tx): bool
    {
        return $tx->amountKobo >= $payment->amount_kobo
            && strtoupper($tx->currency) === strtoupper($payment->currency)
            && ($tx->reference === '' || $tx->reference === $payment->reference);
    }

    private function tryCancelAtGateway(Subscription $subscription): void
    {
        try {
            $this->gateways->gateway($subscription->provider)->cancelSubscription($subscription);
        } catch (\Throwable $e) {
            Log::warning('subscription.refund_cancel_failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }
}
