<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\UecNotification;
use App\Support\Email;
use App\Support\Phone;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Writes one notification_logs row per channel delivery attempt; feeds the
 * dashboard's notification delivery statistics. Destinations are masked.
 */
class LogNotificationDelivery
{
    public function handleSent(NotificationSent $event): void
    {
        $this->write($event->notifiable, $event->notification, $event->channel, 'sent', $event->response);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $error = $event->data['exception'] ?? null;
        $this->write($event->notifiable, $event->notification, $event->channel, 'failed', null,
            $error instanceof Throwable ? $error->getMessage() : (is_string($error) ? $error : 'Delivery failed'));
    }

    private function write(object $notifiable, object $notification, string $channel, string $status, mixed $response, ?string $error = null): void
    {
        $channelName = match ($channel) {
            WhatsAppChannel::class => 'whatsapp',
            FcmChannel::class => 'fcm',
            default => $channel,
        };

        // FCM returns null when there was nothing to send (no devices / not configured).
        if ($channelName === 'fcm' && $status === 'sent' && $response === null) {
            return;
        }
        if ($channelName === 'fcm' && is_array($response) && ($response['sent'] ?? 0) === 0) {
            $status = 'failed';
            $error = 'No device accepted the push';
        }

        try {
            NotificationLog::query()->create([
                'user_id' => $notifiable instanceof User ? $notifiable->id : null,
                'channel' => $channelName,
                'type' => $notification instanceof UecNotification ? $notification->type() : class_basename($notification),
                'destination' => $this->destination($notifiable, $channelName),
                'status' => $status,
                'provider_message_id' => is_string($response) ? mb_substr($response, 0, 191) : null,
                'error' => $error ? mb_substr($error, 0, 500) : null,
            ]);
        } catch (Throwable) {
            // Delivery logging is best-effort.
        }
    }

    private function destination(object $notifiable, string $channel): ?string
    {
        $value = match (true) {
            $notifiable instanceof AnonymousNotifiable => $notifiable->routeNotificationFor($channel === 'whatsapp' ? WhatsAppChannel::class : 'mail'),
            $notifiable instanceof User => $channel === 'whatsapp' ? $notifiable->phone : ($channel === 'mail' ? $notifiable->email : null),
            default => null,
        };

        if (! is_string($value) || $value === '') {
            return $channel === 'fcm' ? 'devices' : null;
        }

        return $channel === 'whatsapp' ? Phone::mask($value) : Email::mask($value);
    }
}
