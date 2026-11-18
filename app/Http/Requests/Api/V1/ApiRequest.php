<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Email;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Server-side password policy: ≥ 8 chars, letters + numbers, not breached (B.1, D.2). */
    protected function passwordRule(): Password
    {
        $rule = Password::min(8)->max(128)->letters()->numbers();

        // Have I Been Pwned k-anonymity range check; fails open if the service is unreachable.
        return config('uec.auth.check_breached_passwords') ? $rule->uncompromised() : $rule;
    }

    protected function phoneRule(): array
    {
        return ['string', 'max:20', 'regex:'.config('uec.auth.phone_regex')];
    }

    /** Normalise phone/email inputs so validation and lookups use canonical values. */
    protected function normalizeIdentifier(string $field, ?string $channel): void
    {
        $value = $this->input($field);
        if (! is_string($value)) {
            return;
        }
        $this->merge([$field => $channel === 'phone' ? Phone::normalize($value) : Email::normalize($value)]);
    }
}
