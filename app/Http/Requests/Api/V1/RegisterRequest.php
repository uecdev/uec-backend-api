<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class RegisterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeIdentifier('phone', 'phone');
        $this->normalizeIdentifier('email', 'email');
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['phone', 'email'])],
            'phone' => ['exclude_unless:channel,phone', 'required', ...$this->phoneRule(), Rule::unique('users', 'phone')],
            'email' => ['exclude_unless:channel,email', 'required', 'string', 'email:rfc', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', $this->passwordRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Nigerian mobile number.',
            'phone.unique' => __('api.already_registered'),
            'email.unique' => __('api.already_registered'),
        ];
    }

    public function destination(): string
    {
        return (string) $this->validated($this->input('channel'));
    }
}
