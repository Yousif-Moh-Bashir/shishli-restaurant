<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdateBranchClosureRequest extends StoreBranchClosureRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.closures.update') ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['starts_at'][0] = 'sometimes';
        $rules['ends_at'][0] = 'sometimes';
        $rules['ends_at'] = array_values(array_filter($rules['ends_at'], fn (string $rule): bool => $rule !== 'after:starts_at'));

        return $rules;
    }
}
