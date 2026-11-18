<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionChanged;
use App\Jobs\SyncSubscriptionWithGateway;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\Data\VerifiedTransaction;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Api\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Subscription lifecycle and entitlements (backend-handoff E.3–E.6).
 *
 * Invariant: a user has at most one non-expired subscription.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly AuditLogger $audit,
    ) {}

    public function current(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', SubscriptionStatus::Expired->value)
            ->with(['plan', 'pendingPlan'])
            ->latest('created_at')
            ->first();
    }

    /** @return list<string> */
    public function entitlements(User $user): array
    {
        $subscription = $this->current($user);

        return $subscription?->grantsAccess() ? array_values(config('uec.subscriptions.entitlements')) : [];
    }

    public function hasEntitlement(?User $user, string $key): bool
    {
        return $user !== null && in_array($key, $this->entitlements($user), true);
    }

    public function isPremium(User $user): bool
    {
        return $this->entitlements($user) !== [];
    }

    /** First successful payment → new active subscription. Caller holds the payment lock. */
    public function activateFromPayment(Payment $payment, VerifiedTransaction $tx): Subscription
    {
        $plan = Plan::query()->findOrFail($payment->plan_id);
        $now = now();

        // Defensive: never leave two live subscriptions (e.g. a stale checkout paid after another succeeded).
        Subscription::query()
            ->where('user_id', $payment->user_id)
            ->where('status', '!=', SubscriptionStatus::Expired->value)
            ->lockForUpdate()
            ->get()
            ->each(function (Subscription $existing) use ($payment) {
                $this->audit->security('duplicate_subscription', 'A second subscription was paid while one was live; the older one was expired.', [
                    'expired_subscription_id' => $existing->id, 'payment_reference' => $payment->reference,
                ], $existing);
                $existing->forceFill(['status' => SubscriptionStatus::Expired, 'expired_at' => now()])->save();
            });

        $periodEnd = $plan->periodEndFrom($now);

        $subscription = Subscription::query()->create([
            'user_id' => $payment->user_id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'provider' => $payment->provider,
            'provider_customer_code' => $tx->customerCode,
            'authorization' => $this->reusableAuthorization($tx),
            'started_at' => $now,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'next_billing_date' => $periodEnd,
        ]);

        $payment->forceFill(['subscription_id' => $subscription->id])->save();

        SubscriptionChanged::dispatch($subscription, 'started');

        return $subscription;
    }

    /** A successful renewal charge extends the period and applies any scheduled plan change. */
    public function renew(Subscription $subscription, Payment $payment, VerifiedTransaction $tx): Subscription
    {
        $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

        $pending = $subscription->pending_plan_id ? Plan::query()->find($subscription->pending_plan_id) : null;
        $planSwitched = $pending && (
            $payment->plan_id === $pending->id
            || ($tx->planCode !== null && $tx->planCode === $pending->paystack_plan_code)
            || $tx->amountKobo === $pending->amount_kobo
        );
        $plan = $planSwitched ? $pending : $subscription->plan;

        $start = $subscription->current_period_end->isFuture() ? $subscription->current_period_end->copy() : now();
        $end = $plan->periodEndFrom($start);

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'next_billing_date' => $end,
            'past_due_at' => null,
            'cancelled_at' => null,
            'access_until' => null,
            'pending_plan_id' => $planSwitched ? null : $subscription->pending_plan_id,
            'pending_plan_effective_at' => $planSwitched ? null : $subscription->pending_plan_effective_at,
            'authorization' => $this->reusableAuthorization($tx) ?? $subscription->authorization,
            'provider_customer_code' => $subscription->provider_customer_code ?? $tx->customerCode,
        ])->save();

        $payment->forceFill(['subscription_id' => $subscription->id, 'plan_id' => $payment->plan_id ?? $plan->id])->save();

        SubscriptionChanged::dispatch($subscription, $planSwitched ? 'plan_changed' : 'renewed');

        return $subscription;
    }

    public function markPastDue(Subscription $subscription, string $reason = 'renewal_failed'): void
    {
        if (in_array($subscription->status, [SubscriptionStatus::Expired, SubscriptionStatus::Cancelled], true)) {
            return;
        }
        if ($subscription->status === SubscriptionStatus::PastDue) {
            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()])->save();
        $this->audit->log('subscription.past_due', $subscription, $reason, actorType: 'system');
        SubscriptionChanged::dispatch($subscription, 'renewal_failed');
    }

    public function expire(Subscription $subscription, string $reason): void
    {
        if ($subscription->status === SubscriptionStatus::Expired) {
            return;
        }

        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'expired_at' => now(),
            'next_billing_date' => null,
            'access_until' => $subscription->access_until && $subscription->access_until->isPast() ? $subscription->access_until : now(),
        ])->save();

        $this->audit->log('subscription.expired', $subscription, $reason, actorType: 'system');
        SubscriptionChanged::dispatch($subscription, 'expired');
    }

    /** Cancel at period end (backend-handoff E.5). Idempotent. */
    public function cancel(Subscription $subscription, ?string $reason = null, string $initiator = 'user'): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return $subscription;
        }
        if ($subscription->status === SubscriptionStatus::Expired) {
            throw new ApiException(409, __('api.subscription_not_active'), 'SUBSCRIPTION_NOT_ACTIVE');
        }

        $synced = $this->tryGateway(fn () => $this->gateways->gateway($subscription->provider)->cancelSubscription($subscription), $subscription, 'cancel');

        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'access_until' => $subscription->status === SubscriptionStatus::PastDue ? now() : $subscription->current_period_end,
            'next_billing_date' => null,
            'pending_plan_id' => null,
            'pending_plan_effective_at' => null,
            'gateway_synced_at' => $synced ? now() : null,
        ])->save();

        if (! $synced) {
            SyncSubscriptionWithGateway::dispatch($subscription->id, 'cancel')->delay(now()->addMinutes(5));
        }

        $this->audit->log('subscription.cancelled', $subscription, "Cancelled by {$initiator}", ['reason' => $reason]);
        SubscriptionChanged::dispatch($subscription, 'cancelled');

        return $subscription->refresh();
    }

    /** @return array{plan: Plan, current_plan: Plan, effective_date: string, amount: int} */
    public function previewChange(Subscription $subscription, Plan $plan): array
    {
        $this->assertChangeAllowed($subscription, $plan);

        return [
            'plan' => $plan,
            'current_plan' => $subscription->plan,
            'effective_date' => $subscription->current_period_end->copy()->timezone(config('uec.timezone'))->toDateString(),
            'amount' => $plan->amount_kobo,
        ];
    }

    /**
     * Schedule a plan change for the next billing date — no proration, no immediate charge.
     * Changing plan (or re-selecting the same plan) while cancelled re-activates auto-renew.
     */
    public function changePlan(Subscription $subscription, Plan $plan): Subscription
    {
        $this->assertChangeAllowed($subscription, $plan);

        $reactivating = $subscription->status === SubscriptionStatus::Cancelled;
        $samePlan = $plan->id === $subscription->plan_id;

        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'access_until' => null,
            'next_billing_date' => $subscription->current_period_end,
            'pending_plan_id' => $samePlan ? null : $plan->id,
            'pending_plan_effective_at' => $samePlan ? null : $subscription->current_period_end,
            'gateway_synced_at' => null,
        ])->save();

        SyncSubscriptionWithGateway::dispatch($subscription->id, 'schedule_renewal')->afterCommit();

        $this->audit->log($reactivating ? 'subscription.reactivated' : 'subscription.plan_change_scheduled', $subscription, null, [
            'plan_id' => $plan->id,
        ]);
        SubscriptionChanged::dispatch($subscription, $reactivating ? 'reactivated' : 'plan_change_scheduled');

        return $subscription->refresh()->load(['plan', 'pendingPlan']);
    }

    private function assertChangeAllowed(Subscription $subscription, Plan $plan): void
    {
        if (! $plan->is_active) {
            throw ApiException::validation('plan_id', __('validation.exists', ['attribute' => 'plan id']));
        }
        if (! $subscription->grantsAccess()) {
            throw new ApiException(409, __('api.subscription_not_active'), 'SUBSCRIPTION_NOT_ACTIVE');
        }

        $effectivePlanId = $subscription->pending_plan_id ?? $subscription->plan_id;
        if ($subscription->status !== SubscriptionStatus::Cancelled && $plan->id === $effectivePlanId) {
            throw new ApiException(409, __('api.same_plan'), 'SAME_PLAN');
        }
    }

    /** Called by the queued sync job and by the scheduler. */
    public function syncWithGateway(Subscription $subscription, string $operation): bool
    {
        $gateway = $this->gateways->gateway($subscription->provider);

        return match ($operation) {
            'cancel' => tap($gateway->cancelSubscription($subscription), function (bool $ok) use ($subscription) {
                if ($ok) {
                    $subscription->forceFill(['gateway_synced_at' => now()])->save();
                }
            }),
            'schedule_renewal' => (function () use ($gateway, $subscription) {
                $plan = $subscription->pendingPlan ?? $subscription->plan;
                $gateway->scheduleRenewal($subscription, $plan);
                $subscription->forceFill(['gateway_synced_at' => now()])->save();

                return true;
            })(),
            default => false,
        };
    }

    /**
     * Periodic housekeeping (scheduler): ends cancelled subscriptions at access_until,
     * marks overdue renewals past_due, and expires past_due after the grace period.
     *
     * @return array{expired: int, past_due: int}
     */
    public function enforceLifecycle(?Carbon $now = null): array
    {
        $now ??= now();
        $expired = 0;
        $pastDue = 0;

        Subscription::query()->status(SubscriptionStatus::Cancelled)
            ->where('access_until', '<=', $now)
            ->each(function (Subscription $s) use (&$expired) {
                $this->expire($s, 'Cancelled subscription reached the end of its paid period.');
                $expired++;
            });

        $graceCutoff = $now->copy()->subDays(config('uec.subscriptions.grace_days'));
        Subscription::query()->status(SubscriptionStatus::PastDue)
            ->where('past_due_at', '<=', $graceCutoff)
            ->each(function (Subscription $s) use (&$expired) {
                $this->expire($s, 'Renewal not paid within the grace period.');
                $expired++;
            });

        $renewalCutoff = $now->copy()->subHours(config('uec.subscriptions.renewal_tolerance_hours'));
        Subscription::query()->status(SubscriptionStatus::Active)
            ->where('current_period_end', '<=', $renewalCutoff)
            ->each(function (Subscription $s) use (&$pastDue) {
                $this->markPastDue($s, 'No renewal payment received after period end.');
                $pastDue++;
            });

        return ['expired' => $expired, 'past_due' => $pastDue];
    }

    private function reusableAuthorization(VerifiedTransaction $tx): ?array
    {
        if (! $tx->authorization || empty($tx->authorization['token']) || ($tx->authorization['reusable'] ?? true) === false) {
            return null;
        }

        return $tx->authorization;
    }

    private function tryGateway(callable $operation, Subscription $subscription, string $name): bool
    {
        try {
            return (bool) $operation();
        } catch (Throwable $e) {
            Log::warning('subscription.gateway_failed', ['subscription_id' => $subscription->id, 'operation' => $name, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
