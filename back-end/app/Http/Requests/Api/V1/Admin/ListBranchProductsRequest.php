<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBranchProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:180'],
            'is_available' => ['sometimes', 'boolean'],
            'available' => ['sometimes', 'boolean'], 'category_uuid' => ['sometimes', 'uuid'], 'sort' => ['sometimes', Rule::in(['product_name', 'price_override', 'is_available', 'created_at', 'updated_at'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['string' => ':attribute يجب أن يكون نصًا.', 'max' => ':attribute يتجاوز الحد المسموح.',
            'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'min' => ':attribute يجب ألا يقل عن :min.',
            'boolean' => 'حالة التوفر غير صحيحة.'];
    }

    public function attributes(): array
    {
        return ['search' => 'البحث', 'category' => 'التصنيف', 'page' => 'الصفحة', 'per_page' => 'حجم الصفحة'];
    }
}
