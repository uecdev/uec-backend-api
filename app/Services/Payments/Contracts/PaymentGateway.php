<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\VerifiedTransaction;
use Illuminate\Http\Request;

/**
 * Provider-agnostic payment operations. Each gateway (Paystack, Monnify)
 * implements this so subscriptions are not coupled to a single vendor.
 */
interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** Start a hosted checkout for a pending payment. */
    public function initialize(Payment $payment, Plan $plan, User $user, string $gatewayCallbackUrl): CheckoutSession;

    /** Server-to-server verification of a transaction by our reference. */
    public function verify(Payment $payment): VerifiedTransaction;

    /** Constant-time signature check over the raw request body. */
    public function verifyWebhookSignature(Request $request): bool;

    /** Idempotency key for a webhook payload. */
    public function webhookEventKey(array $payload): string;

    /** Event type name for a webhook payload. */
    public function webhookEventType(array $payload): ?string;

    /** Stop auto-renewal at the gateway. Returns true when the gateway confirmed it. */
    public function cancelSubscription(Subscription $subscription): bool;

    /**
     * Prepare the gateway so the next renewal bills the subscription's (new) plan,
     * starting at current_period_end. Used for scheduled plan changes and re-activation.
     */
    public function scheduleRenewal(Subscription $subscription, Plan $plan): void;

    /**
     * Charge a stored authorization for a renewal when the gateway does not run
     * the recurring schedule itself. Returns null when the gateway bills automatically.
     */
    public function chargeRenewal(Subscription $subscription, Payment $payment): ?VerifiedTransaction;

    /** Cheap authenticated call used by the admin "test connection" action. */
    public function ping(): bool;
}
