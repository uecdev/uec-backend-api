<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class LoginRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeIdentifier('identifier', $this->input('channel'));
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['phone', 'email'])],
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
