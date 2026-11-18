<?php

namespace App\Listeners;

use App\Events\SubscriptionChanged;
use App\Notifications\SubscriptionNotification;

class SendSubscriptionNotice
{
    public function handle(SubscriptionChanged $event): void
    {
        $user = $event->subscription->user;
        if (! $user || $user->trashed()) {
            return;
        }

        $user->notify(new SubscriptionNotification($event->subscription, $event->event));
    }
}
