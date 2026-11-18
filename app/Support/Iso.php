<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Contract date formats (backend-handoff C.1). */
final class Iso
{
    /** ISO-8601 UTC timestamp, e.g. 2026-01-01T09:00:00Z */
    public static function ts(?DateTimeInterface $value): ?string
    {
        return $value ? Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** Calendar date without timezone, e.g. 2026-01-05 */
    public static function date(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
