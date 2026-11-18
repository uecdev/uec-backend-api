<?php

namespace App\Enums;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
    case Web = 'web';

    public static function fromHeader(?string $value): ?self
    {
        return $value ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
