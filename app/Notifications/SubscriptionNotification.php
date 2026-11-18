<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Messages\PushMessage;
use App\Notifications\Messages\WhatsAppTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Subscription notices (backend-handoff H.2): started, renewed, renewal failed,
 * cancelled, expired, plan change scheduled, reactivated.
 */
class SubscriptionNotification extends UecNotification
{
    /** Events that also go out as a push notification (renewal/failure are "direct to user", H.1). */
    private const PUSH_EVENTS = ['started', 'renewal_failed', 'expired'];

    public function __construct(public readonly Subscription $subscription, public readonly string $event)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'subscription_'.$this->event;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->subscription->loadMissing(['plan', 'pendingPlan']);
        $tz = config('uec.timezone');

        return (new MailMessage)
            ->subject(__("notifications.subscription.{$this->event}.subject"))
            ->greeting(__('notifications.greeting_generic'))
            ->line(__("notifications.subscription.{$this->event}.body", [
                'plan' => $s->plan?->name,
                'new_plan' => $s->pendingPlan?->name,
                'date' => ($s->access_until ?? $s->current_period_end)?->timezone($tz)->format('j F Y'),
                'grace' => config('uec.subscriptions.grace_days'),
            ]));
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplate
    {
        return $this->template([$this->subscription->plan?->name ?? 'UEC Premium']);
    }

    public function toFcm(object $notifiable): ?PushMessage
    {
        if (! in_array($this->event, self::PUSH_EVENTS, true)) {
            return null;
        }

        return $this->push(
            __("notifications.subscription.{$this->event}.subject"),
            __("notifications.subscription.{$this->event}.push"),
            '/subscription',
        );
    }
}
