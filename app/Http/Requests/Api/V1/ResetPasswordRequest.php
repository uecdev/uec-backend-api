<?php

namespace App\Http\Requests\Api\V1;

class ResetPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', $this->passwordRule()],
        ];
    }
}
