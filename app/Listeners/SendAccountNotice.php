<?php

namespace App\Listeners;

use App\Events\AccountEvent;
use App\Notifications\AccountNotice;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Support\Facades\Notification;

class SendAccountNotice
{
    private const MAP = [
        'registered' => AccountNotice::WELCOME,
        'password_changed' => AccountNotice::PASSWORD_CHANGED,
        'email_changed' => AccountNotice::EMAIL_CHANGED,
        'phone_changed' => AccountNotice::PHONE_CHANGED,
        'deleted' => AccountNotice::ACCOUNT_DELETED,
    ];

    public function handle(AccountEvent $event): void
    {
        $type = self::MAP[$event->event] ?? null;
        if (! $type) {
            return;
        }

        $notice = (new AccountNotice($type, $event->name))->locale($event->locale);

        // Deleted accounts and changed contacts are notified at the captured (previous) address.
        if ($event->event === 'deleted' || $event->email || $event->phone) {
            $routes = array_filter(['mail' => $event->email, WhatsAppChannel::class => $event->phone]);
            if ($routes) {
                Notification::routes($routes)->notify($notice);
            }

            return;
        }

        $event->user?->notify($notice);
    }
}
