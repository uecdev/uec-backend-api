<?php

namespace App\Listeners;

use App\Events\ContentPublished;
use App\Services\Content\ContentCache;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Rebuilds offline bundles and invalidates catalogue caches after content changes. */
class RefreshContentCaches implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly ContentCache $cache) {}

    public function handle(ContentPublished $event): void
    {
        match ($event->type) {
            'devotional' => $event->year ? $this->cache->rebuildDevotionalBundle($event->year) : null,
            'sunday_school' => $event->year ? $this->cache->rebuildManualBundle($event->year) : null,
            'hymns' => $this->cache->forgetHymnCatalogue(),
            default => null,
        };
    }
}
