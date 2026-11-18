<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Payments\Webhooks\MonnifyWebhookHandler;
use App\Services\Payments\Webhooks\PaystackWebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function __construct(public readonly string $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if (! $event || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->increment('attempts');

        $handler = match ($event->provider) {
            'paystack' => app(PaystackWebhookHandler::class),
            'monnify' => app(MonnifyWebhookHandler::class),
        };

        try {
            $outcome = $handler->handle($event->payload);
            $event->forceFill(['status' => $outcome, 'processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            Log::error('webhook.processing_failed', ['id' => $event->id, 'provider' => $event->provider, 'error' => $e->getMessage()]);

            throw $e; // let the queue retry with backoff
        }
    }
}
