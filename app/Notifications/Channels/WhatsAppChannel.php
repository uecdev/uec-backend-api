<?php

namespace App\Notifications\Channels;

use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\User;
use App\Notifications\Messages\WhatsAppTemplate;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppClient $client) {}

    public function send(object $notifiable, Notification $notification): ?string
    {
        $phone = $notifiable instanceof AnonymousNotifiable
            ? $notifiable->routeNotificationFor(self::class)
            : ($notifiable instanceof User ? $notifiable->phone : null);

        if (! $phone || ! method_exists($notification, 'toWhatsApp')) {
            return null;
        }

        /** @var WhatsAppTemplate|null $template */
        $template = $notification->toWhatsApp($notifiable);
        if (! $template) {
            return null;
        }

        $locale = $notifiable instanceof User ? $notifiable->preferredLocale() : null;

        return $this->client->sendTemplate($phone, $template->name, $template->components(), $locale === 'en' ? null : $locale);
    }
}
