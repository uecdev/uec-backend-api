<?php

namespace App\Notifications\Channels;

use App\Integrations\Firebase\FcmClient;
use App\Models\Device;
use App\Models\User;
use App\Notifications\Messages\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * Pushes a notice to every registered device of a user; tokens FCM reports
 * as UNREGISTERED/invalid are removed (backend-handoff H.1).
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

    /** @return array{sent: int, invalid: int, failed: int}|null */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toFcm') || ! $this->fcm->isConfigured()) {
            return null;
        }

        /** @var PushMessage|null $message */
        $message = $notification->toFcm($notifiable);
        $tokens = $notifiable->devices()->pluck('token')->all();
        if (! $message || $tokens === []) {
            return null;
        }

        $results = $this->fcm->sendMany($tokens, $message->title, $message->body, $message->data());
        $invalid = array_keys(array_filter($results, fn ($r) => $r === FcmClient::RESULT_INVALID_TOKEN));
        if ($invalid) {
            Device::query()->whereIn('token', $invalid)->delete();
        }

        return [
            'sent' => count(array_filter($results, fn ($r) => $r === FcmClient::RESULT_OK)),
            'invalid' => count($invalid),
            'failed' => count(array_filter($results, fn ($r) => $r === FcmClient::RESULT_ERROR)),
        ];
    }
}
