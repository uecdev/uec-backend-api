<?php

namespace App\Services\Payments\Webhooks;

use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionChanged;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\Carbon;

class PaystackWebhookHandler
{
    public function __construct(
        private readonly PaystackGateway $gateway,
        private readonly PaymentService $payments,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string outcome: processed | ignored */
    public function handle(array $payload): string
    {
        $data = (array) ($payload['data'] ?? []);

        return match ($payload['event'] ?? null) {
            'charge.success' => $this->chargeSuccess($data),
            'subscription.create' => $this->subscriptionCreated($data),
            'subscription.not_renew', 'subscription.disable' => $this->subscriptionDisabled($data),
            'invoice.payment_failed' => $this->invoiceFailed($data),
            'refund.processed' => $this->refundProcessed($data),
            default => 'ignored', // invoice.create / invoice.update and others are informational
        };
    }

    private function chargeSuccess(array $data): string
    {
        $tx = $this->gateway->mapTransaction($data);

        if ($payment = Payment::query()->where('reference', $tx->reference)->first()) {
            $this->payments->apply($payment, $tx, 'webhook');

            return 'processed';
        }

        // A charge Paystack initiated itself: a subscription renewal.
        $subscription = $this->findByCustomer($tx->customerCode);
        if (! $subscription || ! $tx->planCode) {
            return 'ignored';
        }
        $this->payments->recordGatewayRenewal($subscription, $tx);

        return 'processed';
    }

    private function subscriptionCreated(array $data): string
    {
        $subscription = $this->findByCustomer($data['customer']['customer_code'] ?? null, includeCancelled: true);
        if (! $subscription) {
            return 'ignored';
        }

        $subscription->forceFill([
            'provider_subscription_code' => $data['subscription_code'] ?? $subscription->provider_subscription_code,
            'provider_email_token' => $data['email_token'] ?? $subscription->provider_email_token,
            'next_billing_date' => ! empty($data['next_payment_date']) && $subscription->status === SubscriptionStatus::Active
                ? Carbon::parse($data['next_payment_date'])
                : $subscription->next_billing_date,
            'gateway_synced_at' => now(),
        ])->save();

        // The user cancelled before Paystack reported the subscription: disable it now.
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            $this->subscriptions->syncWithGateway($subscription, 'cancel');
        }

        return 'processed';
    }

    private function subscriptionDisabled(array $data): string
    {
        $code = $data['subscription_code'] ?? null;
        $subscription = $code ? Subscription::query()->where('provider_subscription_code', $code)->first() : null;

        // Codes we replaced during a plan change no longer match; nothing to do.
        if (! $subscription || $subscription->status !== SubscriptionStatus::Active) {
            return 'ignored';
        }

        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Auto-renew disabled at Paystack',
            'access_until' => $subscription->current_period_end,
            'next_billing_date' => null,
            'gateway_synced_at' => now(),
        ])->save();
        $this->audit->log('subscription.cancelled', $subscription, 'Disabled at Paystack', actorType: 'gateway');
        SubscriptionChanged::dispatch($subscription, 'cancelled');

        return 'processed';
    }

    private function invoiceFailed(array $data): string
    {
        $code = $data['subscription']['subscription_code'] ?? null;
        $subscription = $code ? Subscription::query()->where('provider_subscription_code', $code)->first() : null;
        if (! $subscription) {
            return 'ignored';
        }
        $this->subscriptions->markPastDue($subscription, 'Paystack invoice payment failed');

        return 'processed';
    }

    private function refundProcessed(array $data): string
    {
        $reference = $data['transaction_reference'] ?? $data['transaction']['reference'] ?? null;
        $payment = $reference ? Payment::query()->where('reference', $reference)->first() : null;
        if (! $payment) {
            return 'ignored';
        }
        $this->payments->refund($payment, 'webhook', ['refund_id' => $data['id'] ?? null, 'amount' => $data['amount'] ?? null]);

        return 'processed';
    }

    private function findByCustomer(?string $customerCode, bool $includeCancelled = false): ?Subscription
    {
        if (! $customerCode) {
            return null;
        }
        $statuses = [SubscriptionStatus::Active, SubscriptionStatus::PastDue];
        if ($includeCancelled) {
            $statuses[] = SubscriptionStatus::Cancelled;
        }

        return Subscription::query()
            ->where('provider', 'paystack')
            ->where('provider_customer_code', $customerCode)
            ->status(...$statuses)
            ->latest('created_at')
            ->first();
    }
}
