<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when devotional or Sunday School content changes, so offline bundles
 * and caches are rebuilt. $type ∈ devotional | sunday_school | hymns.
 */
class ContentPublished
{
    use Dispatchable;

    public function __construct(public string $type, public ?int $year = null) {}
}
