<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\WhatsAppTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Account lifecycle notices: welcome, password changed, contact changed, account deleted.
 */
class AccountNotice extends UecNotification
{
    public const WELCOME = 'welcome';

    public const PASSWORD_CHANGED = 'password_changed';

    public const EMAIL_CHANGED = 'email_changed';

    public const PHONE_CHANGED = 'phone_changed';

    public const ACCOUNT_DELETED = 'account_deleted';

    public function __construct(public readonly string $event, public readonly ?string $name = null)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->event;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->name ?? ($notifiable instanceof User ? $notifiable->name : null);

        return (new MailMessage)
            ->subject(__("notifications.{$this->event}.subject"))
            ->greeting($name ? __('notifications.greeting', ['name' => $name]) : __('notifications.greeting_generic'))
            ->line(__("notifications.{$this->event}.body"))
            ->when($this->event !== self::WELCOME && $this->event !== self::ACCOUNT_DELETED,
                fn (MailMessage $m) => $m->line(__('notifications.not_you')));
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplate
    {
        return $this->template([$this->name ?? ($notifiable instanceof User ? ($notifiable->name ?: 'there') : 'there')]);
    }
}
