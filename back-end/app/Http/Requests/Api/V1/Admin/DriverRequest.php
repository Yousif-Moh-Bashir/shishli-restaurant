<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('drivers.manage') ?? false;
    }

    public function rules(): array
    {
        $driver = $this->route('driver');

        return ['name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^\\+[1-9][0-9]{7,14}$/D', Rule::unique('users', 'phone')->ignore($driver), Rule::unique('users', 'phone_normalized')->ignore($driver)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($driver)],
            'password' => [$driver ? 'sometimes' : 'required', 'required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()],
            'branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'enabled' => ['required', 'boolean'], 'available' => ['required', 'boolean']];
    }
}
