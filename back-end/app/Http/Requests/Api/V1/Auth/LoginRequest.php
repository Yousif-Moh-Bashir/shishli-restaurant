<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_without:phone', 'prohibits:phone', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'prohibits:email', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'password' => ['required', 'string', 'max:72'],
        ];
    }
}
