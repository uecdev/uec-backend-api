<?php

namespace App\Support;

final class Kobo
{
    public static function format(int $kobo, string $currency = 'NGN'): string
    {
        $symbol = $currency === 'NGN' ? '₦' : $currency.' ';

        return $symbol.number_format($kobo / 100, 2);
    }
}
