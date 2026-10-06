<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Admin\AdminAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('staffUser');
        $create = $this->isMethod('post');

        return ['name' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'phone' => [$create ? 'required' : 'sometimes', 'required', 'string', 'regex:/^\\+[1-9][0-9]{7,14}$/D', Rule::unique('users', 'phone')->ignore($user), Rule::unique('users', 'phone_normalized')->ignore($user)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()],
            'roles' => [$create ? 'required' : 'sometimes', 'required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(AdminAccessService::STAFF_ROLES)]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, ['name', 'phone', 'email', 'password', 'roles'], true)) {
                    $validator->errors()->add($key, 'Unknown or immutable field.');
                }
            }
        }];
    }
}
