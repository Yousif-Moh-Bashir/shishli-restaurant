<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.closures.create') ?? false;
    }

    public function rules(): array
    {
        $iso = 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D';

        return ['starts_at' => ['required', 'date', $iso], 'ends_at' => ['required', 'date', $iso, 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:1000'], 'is_active' => ['sometimes', 'boolean']];
    }
}
