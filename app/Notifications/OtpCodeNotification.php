<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Email OTP. Sent synchronously from the DeliverOtp job (already on the "otp" queue). */
class OtpCodeNotification extends UecNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code, public readonly string $purpose)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'otp.'.$this->purpose;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) (config('uec.otp.ttl_seconds') / 60);

        return (new MailMessage)
            ->subject(__('notifications.otp.subject', ['code' => $this->code]))
            ->greeting(__('notifications.greeting_generic'))
            ->line(__('notifications.otp.intro.'.$this->purpose))
            ->line('**'.$this->code.'**')
            ->line(__('notifications.otp.expiry', ['minutes' => $minutes]))
            ->line(__('notifications.otp.ignore'));
    }
}
