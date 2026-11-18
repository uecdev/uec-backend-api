<?php

namespace App\Support;

final class Email
{
    public static function normalize(?string $input): ?string
    {
        $value = $input === null ? null : mb_strtolower(trim($input));

        return $value === '' ? null : $value;
    }

    /** "praise@example.com" -> "pr•••@example.com" */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.'•••@'.$domain;
    }

    public static function maskDestination(string $channel, string $destination): string
    {
        return $channel === 'phone' ? Phone::mask($destination) : self::mask($destination);
    }
}
