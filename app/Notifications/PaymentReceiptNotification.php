<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Messages\WhatsAppTemplate;
use App\Support\Kobo;
use Illuminate\Notifications\Messages\MailMessage;

/** Receipt for every successful charge, first purchase and renewals (backend-handoff E.7). */
class PaymentReceiptNotification extends UecNotification
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'payment_receipt';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment->loadMissing('plan');
        $paidAt = ($p->paid_at ?? now())->timezone(config('uec.timezone'));

        return (new MailMessage)
            ->subject(__('notifications.payment_receipt.subject'))
            ->greeting(__('notifications.greeting_generic'))
            ->line(__('notifications.payment_receipt.body'))
            ->line(__('notifications.payment_receipt.plan', ['plan' => $p->plan?->name ?? '—']))
            ->line(__('notifications.payment_receipt.amount', ['amount' => Kobo::format($p->amount_kobo, $p->currency)]))
            ->line(__('notifications.payment_receipt.reference', ['reference' => $p->reference]))
            ->line(__('notifications.payment_receipt.date', ['date' => $paidAt->format('j F Y, g:i a')]))
            ->line(__('notifications.payment_receipt.provider', ['provider' => ucfirst($p->provider)]));
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplate
    {
        return $this->template([Kobo::format($this->payment->amount_kobo, $this->payment->currency), $this->payment->reference]);
    }
}
