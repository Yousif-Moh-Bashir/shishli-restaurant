<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('branch'))) {
            $this->merge(['branch' => strtolower($this->input('branch'))]);
        }
    }

    public function rules(): array
    {
        return [
            'branch' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')->where('is_active', true)],
            'category' => ['nullable', 'string', 'max:180'],
            'search' => ['nullable', 'string', 'max:150'],
            'featured' => ['nullable', 'boolean'],
            'available' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch.required' => 'يرجى تحديد الفرع.', 'branch.uuid' => 'معرف الفرع غير صحيح.',
            'branch.exists' => 'الفرع المحدد غير متاح.', 'string' => ':attribute يجب أن يكون نصًا.',
            'max' => ':attribute يتجاوز الحد المسموح.', 'boolean' => 'قيمة :attribute غير صحيحة.',
        ];
    }

    public function attributes(): array
    {
        return ['category' => 'التصنيف', 'search' => 'البحث', 'featured' => 'المنتجات المميزة', 'available' => 'التوفر'];
    }
}
