<?php

namespace App\Integrations\ZeptoMail;

use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport for Zoho ZeptoMail's HTTP API (v1.1/email).
 * Registered as the "zeptomail" mailer, so every Laravel Mailable and mail
 * notification is delivered through ZeptoMail when MAIL_MAILER=zeptomail.
 */
class ZeptoMailTransport extends AbstractTransport
{
    use LogsIntegrationCalls;

    public function __construct(private readonly string $token, private readonly string $host, private readonly ?string $bounceAddress = null)
    {
        parent::__construct();
    }

    protected function integrationName(): string
    {
        return 'zeptomail';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw IntegrationException::notConfigured('zeptomail');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;

        $payload = array_filter([
            'from' => $from ? ['address' => $from->getAddress(), 'name' => $from->getName()] : null,
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()) ?: null,
            'bcc' => $this->addresses($email->getBcc()) ?: null,
            'reply_to' => array_map(fn (Address $a) => ['address' => $a->getAddress(), 'name' => $a->getName()], $email->getReplyTo()) ?: null,
            'subject' => $email->getSubject(),
            'htmlbody' => $email->getHtmlBody(),
            'textbody' => $email->getTextBody(),
            'bounce_address' => $this->bounceAddress,
            'track_clicks' => false,
            'track_opens' => false,
        ], fn ($v) => $v !== null);

        $url = 'https://'.$this->host.'/v1.1/email';
        $response = $this->call('email.send', 'POST', '/v1.1/email', fn () => $this->http((int) config('services.zeptomail.timeout', 15), 1)
            ->withHeaders(['Authorization' => 'Zoho-enczapikey '.$this->token])
            ->post($url, $payload));

        if (! $response->successful()) {
            throw new IntegrationException('zeptomail', (string) ($response->json('error.message') ?? 'Email rejected.'), $response->status());
        }

        if ($id = $response->json('request_id')) {
            $message->setMessageId((string) $id);
        }
    }

    /** @param list<Address> $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => ['email_address' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName()])], $addresses);
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
