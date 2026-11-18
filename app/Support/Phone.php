<?php

namespace App\Support;

final class Phone
{
    /**
     * Normalise common Nigerian formats to E.164 (+234XXXXXXXXXX).
     * The app normalises before sending; this is defence in depth.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', trim($input)) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '234')) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+234'.substr($digits, 1);
        }

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $e164): bool
    {
        return $e164 !== null && preg_match(config('uec.auth.phone_regex'), $e164) === 1;
    }

    /** "+2348123456789" -> "+234 ••• ••• 6789" (backend-handoff D.1). */
    public static function mask(string $e164): string
    {
        return substr($e164, 0, 4).' ••• ••• '.substr($e164, -4);
    }
}
