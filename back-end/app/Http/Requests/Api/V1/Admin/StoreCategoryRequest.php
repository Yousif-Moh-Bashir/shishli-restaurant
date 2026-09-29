<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('categories', 'uuid')
                    ->whereNull('deleted_at'),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:180',
                Rule::unique('categories', 'slug')
                    ->whereNull('deleted_at'),
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'image' => [
                'nullable',
                'string',
                'max:500',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_uuid.uuid' => 'معرف القسم الرئيسي غير صحيح.',

            'parent_uuid.exists' => 'القسم الرئيسي المحدد غير موجود.',

            'name.required' => 'اسم القسم مطلوب.',

            'name.string' => 'اسم القسم يجب أن يكون نصًا.',

            'name.max' => 'اسم القسم يجب ألا يتجاوز 150 حرفًا.',

            'slug.unique' => 'الرابط المختصر مستخدم مسبقًا.',

            'description.max' => 'وصف القسم يجب ألا يتجاوز 2000 حرف.',

            'sort_order.integer' => 'ترتيب القسم يجب أن يكون رقمًا صحيحًا.',

            'sort_order.min' => 'ترتيب القسم لا يمكن أن يكون أقل من صفر.',

            'is_active.boolean' => 'حالة القسم غير صحيحة.',
        ];
    }
}
