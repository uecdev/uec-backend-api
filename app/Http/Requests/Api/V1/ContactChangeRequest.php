<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class ContactChangeRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeIdentifier('value', $this->input('channel'));
    }

    public function rules(): array
    {
        $value = $this->input('channel') === 'phone'
            ? ['required', ...$this->phoneRule()]
            : ['required', 'string', 'email:rfc', 'max:191'];

        return [
            'channel' => ['required', Rule::in(['phone', 'email'])],
            'value' => $value,
        ];
    }

    public function messages(): array
    {
        return ['value.regex' => 'Enter a valid Nigerian mobile number.'];
    }
}
