<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'featured' => ['sometimes', 'boolean'],
            'available' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:default,price_asc,price_desc,name,latest'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.string' => 'معرف القسم يجب أن يكون نصًا.',
            'category.max' => 'معرف القسم يجب ألا يتجاوز 255 حرفًا.',
            'branch.string' => 'معرف الفرع يجب أن يكون نصًا.',
            'branch.max' => 'معرف الفرع يجب ألا يتجاوز 255 حرفًا.',
            'search.string' => 'عبارة البحث يجب أن تكون نصًا.',
            'search.max' => 'عبارة البحث يجب ألا تتجاوز 255 حرفًا.',
            'sort.string' => 'طريقة الترتيب غير صحيحة.',
            'sort.in' => 'طريقة الترتيب غير مسموح بها.',
            'featured.boolean' => 'قيمة تصفية المنتجات المميزة غير صحيحة.',
            'available.boolean' => 'قيمة تصفية المنتجات المتوفرة غير صحيحة.',
            'is_active.boolean' => 'قيمة تصفية حالة التفعيل غير صحيحة.',
            'is_available.boolean' => 'قيمة تصفية حالة التوفر غير صحيحة.',
            'is_featured.boolean' => 'قيمة تصفية حالة التمييز غير صحيحة.',
            'page.integer' => 'رقم الصفحة يجب أن يكون عددًا صحيحًا.',
            'page.min' => 'رقم الصفحة يجب ألا يقل عن 1.',
            'page.max' => 'رقم الصفحة كبير جدًا.',
            'per_page.integer' => 'عدد المنتجات في الصفحة يجب أن يكون عددًا صحيحًا.',
            'per_page.min' => 'عدد المنتجات في الصفحة يجب ألا يقل عن 1.',
            'per_page.max' => 'عدد المنتجات في الصفحة يجب ألا يتجاوز 100.',
        ];
    }
}
