<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachBranchProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('product_uuid'))) {
            $this->merge(['product_uuid' => strtolower($this->input('product_uuid'))]);
        }
    }

    public function rules(): array
    {
        return [
            'product_uuid' => ['required', 'uuid', Rule::exists('products', 'uuid')->whereNull('deleted_at')],
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute مطلوب.', 'uuid' => 'معرف المنتج غير صحيح.',
            'exists' => 'المنتج المحدد غير موجود.', 'numeric' => 'السعر يجب أن يكون رقمًا.',
            'min' => ':attribute يجب ألا يقل عن :min.', 'max' => ':attribute يتجاوز الحد المسموح (:max).',
            'decimal' => 'السعر يجب ألا يتجاوز منزلتين عشريتين.', 'boolean' => 'حالة التوفر غير صحيحة.',
            'array' => 'قائمة المنتجات أو بياناتها غير صحيحة.', 'distinct' => 'لا يمكن تكرار المنتج في الطلب.',
        ];
    }

    public function attributes(): array
    {
        return ['product_uuid' => 'المنتج', 'price_override' => 'السعر الخاص', 'is_available' => 'حالة التوفر',
            'products' => 'المنتجات', 'products.*.product_uuid' => 'المنتج', 'products.*.price_override' => 'السعر الخاص'];
    }
}
