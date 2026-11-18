<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Notifications\PaymentReceiptNotification;

class SendPaymentReceipt
{
    public function handle(PaymentSucceeded $event): void
    {
        $user = $event->payment->user;
        if ($user && ! $user->trashed()) {
            $user->notify(new PaymentReceiptNotification($event->payment));
        }
    }
}
