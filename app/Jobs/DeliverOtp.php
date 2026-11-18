<?php

namespace App\Jobs;

use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\NotificationLog;
use App\Notifications\OtpCodeNotification;
use App\Support\Email;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends an OTP: phone → WhatsApp authentication template; email → ZeptoMail.
 * Runs on the high-priority "otp" queue. Codes are never logged.
 */
class DeliverOtp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $channel,
        public readonly string $destination,
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $purpose,
        public readonly string $locale = 'en',
    ) {}

    public function handle(WhatsAppClient $whatsapp): void
    {
        $masked = Email::maskDestination($this->channel, $this->destination);

        try {
            if ($this->channel === 'phone') {
                if (! $whatsapp->isConfigured() && App::environment('local', 'testing')) {
                    Log::info('otp.whatsapp_not_configured', ['destination' => $masked]);
                    $this->log('skipped', $masked, null, 'WhatsApp not configured');

                    return;
                }
                $id = $whatsapp->sendOtp($this->destination, $this->code, $this->locale === 'en' ? null : $this->locale);
                $this->log('sent', $masked, $id);

                return;
            }

            Notification::route('mail', $this->destination)
                ->notifyNow((new OtpCodeNotification($this->code, $this->purpose))->locale($this->locale));
        } catch (Throwable $e) {
            $this->log('failed', $masked, null, $e->getMessage());
            throw $e;
        }
    }

    private function log(string $status, string $masked, ?string $providerId, ?string $error = null): void
    {
        NotificationLog::query()->create([
            'channel' => $this->channel === 'phone' ? 'whatsapp' : 'mail',
            'type' => 'otp.'.$this->purpose,
            'destination' => $masked,
            'status' => $status,
            'provider_message_id' => $providerId,
            'error' => $error ? mb_substr($error, 0, 500) : null,
        ]);
    }
}
