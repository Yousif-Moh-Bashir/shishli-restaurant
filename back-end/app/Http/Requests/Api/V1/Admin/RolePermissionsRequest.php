<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class RolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['permissions' => ['present', 'array', 'max:200'], 'permissions.*' => ['required', 'string', 'distinct', Rule::in(Permission::where('guard_name', 'web')->pluck('name')->all())]];
    }
}
