<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('branch_uuid'))) {
            $this->merge(['branch_uuid' => strtolower($this->input('branch_uuid'))]);
        }
    }

    public function rules(): array
    {
        return ['branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')->where('is_active', true)]];
    }

    public function messages(): array
    {
        return ['branch_uuid.required' => 'يرجى تحديد الفرع.', 'branch_uuid.uuid' => 'معرف الفرع غير صحيح.', 'branch_uuid.exists' => 'الفرع المحدد غير متاح.'];
    }
}
