<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'phone' => ['prohibited'], 'phone_normalized' => ['prohibited'], 'phone_verified_at' => ['prohibited'],
            'is_active' => ['prohibited'], 'password' => ['prohibited'], 'roles' => ['prohibited']];
    }
}
