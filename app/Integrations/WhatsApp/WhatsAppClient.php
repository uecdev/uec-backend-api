<?php

namespace App\Integrations\WhatsApp;

use App\Integrations\Concerns\LogsIntegrationCalls;
use App\Integrations\IntegrationException;

/**
 * WhatsApp Business Platform (Cloud API). Only pre-approved templates are sent:
 * the authentication template for OTPs (with copy-code button) and optional
 * utility templates for transactional notices.
 */
class WhatsAppClient
{
    use LogsIntegrationCalls;

    protected function integrationName(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /** Send the approved authentication template. Returns the WhatsApp message id. */
    public function sendOtp(string $e164, string $code, ?string $language = null): string
    {
        return $this->sendTemplate($e164, (string) config('services.whatsapp.otp_template'), [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
            // Authentication templates with a copy-code button take the code as the URL button parameter.
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
        ], $language);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(string $e164, string $template, array $components = [], ?string $language = null): string
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('whatsapp');
        }

        $endpoint = sprintf('https://graph.facebook.com/%s/%s/messages', config('services.whatsapp.graph_version'), config('services.whatsapp.phone_number_id'));

        $response = $this->call('messages.template', 'POST', '/{phone_number_id}/messages', fn () => $this->http((int) config('services.whatsapp.timeout', 15), 1)
            ->withToken((string) config('services.whatsapp.token'))
            ->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($e164, '+'),
                'type' => 'template',
                'template' => array_filter([
                    'name' => $template,
                    'language' => ['code' => $language ?: config('services.whatsapp.template_language')],
                    'components' => $components ?: null,
                ]),
            ]));

        if (! $response->successful()) {
            throw new IntegrationException('whatsapp', (string) ($response->json('error.message') ?? 'Message rejected.'), $response->status());
        }

        return (string) $response->json('messages.0.id', '');
    }

    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $endpoint = sprintf('https://graph.facebook.com/%s/%s', config('services.whatsapp.graph_version'), config('services.whatsapp.phone_number_id'));

        return $this->call('phone_number.fetch', 'GET', '/{phone_number_id}', fn () => $this->http(10, 0)
            ->withToken((string) config('services.whatsapp.token'))
            ->get($endpoint, ['fields' => 'display_phone_number']))->successful();
    }
}
