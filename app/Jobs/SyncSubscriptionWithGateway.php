<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retries gateway-side changes (disable auto-renew, schedule a plan change)
 * so a provider outage never blocks the user's action in the app.
 */
class SyncSubscriptionWithGateway implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function __construct(public readonly string $subscriptionId, public readonly string $operation) {}

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscription = Subscription::query()->with(['plan', 'pendingPlan', 'user'])->find($this->subscriptionId);
        if (! $subscription) {
            return;
        }

        if (! $subscriptions->syncWithGateway($subscription, $this->operation)) {
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);
        }
    }
}
