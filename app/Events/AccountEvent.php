<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Account lifecycle: registered, password_changed, email_changed, phone_changed, deleted.
 * For "deleted", the user row is already anonymised, so contact details are carried here.
 */
class AccountEvent
{
    use Dispatchable;

    public function __construct(
        public string $event,
        public ?User $user = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $name = null,
        public string $locale = 'en',
    ) {}
}
