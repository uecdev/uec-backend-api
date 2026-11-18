<?php

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on every subscription lifecycle transition.
 * $event ∈ started | renewed | plan_changed | renewal_failed | cancelled | expired | plan_change_scheduled | reactivated
 */
class SubscriptionChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription, public string $event) {}
}
