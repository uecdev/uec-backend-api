<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Refunded], true);
    }
}
