<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\PushMessage;
use App\Notifications\Messages\WhatsAppTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/**
 * Base for transactional notices (backend-handoff H.2). Channel selection:
 *  - mail     when the recipient has an email address (ZeptoMail)
 *  - whatsapp when the recipient has a phone AND an approved template is configured for this type
 *  - fcm      when the notice defines a push message and the user has registered devices
 */
abstract class UecNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** Stable type key, used for WhatsApp template lookup and delivery logs. */
    abstract public function type(): string;

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($this->emailFor($notifiable)) {
            $channels[] = 'mail';
        }
        if ($this->phoneFor($notifiable) && isset(config('services.whatsapp.templates')[$this->type()]) && method_exists($this, 'toWhatsApp')) {
            $channels[] = WhatsAppChannel::class;
        }
        if ($notifiable instanceof User && method_exists($this, 'toFcm') && $notifiable->devices()->exists()) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    protected function emailFor(object $notifiable): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return $notifiable->routeNotificationFor('mail');
        }

        return $notifiable instanceof User ? $notifiable->email : null;
    }

    protected function phoneFor(object $notifiable): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return $notifiable->routeNotificationFor(WhatsAppChannel::class);
        }

        return $notifiable instanceof User ? $notifiable->phone : null;
    }

    protected function template(array $params = []): WhatsAppTemplate
    {
        return new WhatsAppTemplate((string) config('services.whatsapp.templates')[$this->type()], $params);
    }

    protected function push(string $title, string $body, ?string $route = null): PushMessage
    {
        return new PushMessage($title, $body, $route, $this->type());
    }
}
