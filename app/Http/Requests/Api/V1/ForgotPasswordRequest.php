<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class ForgotPasswordRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeIdentifier('identifier', $this->input('channel'));
    }

    public function rules(): array
    {
        $identifier = $this->input('channel') === 'phone'
            ? ['required', ...$this->phoneRule()]
            : ['required', 'string', 'email:rfc', 'max:191'];

        return [
            'channel' => ['required', Rule::in(['phone', 'email'])],
            'identifier' => $identifier,
        ];
    }

    public function messages(): array
    {
        return ['identifier.regex' => 'Enter a valid Nigerian mobile number.'];
    }
}
