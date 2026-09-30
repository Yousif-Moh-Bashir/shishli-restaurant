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
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('categories', 'slug'),
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'image' => [
                'nullable',
                'string',
                'max:255',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:2147483647',
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
            'slug.string' => 'الرابط المختصر يجب أن يكون نصًا.',
            'slug.max' => 'الرابط المختصر يجب ألا يتجاوز 180 حرفًا.',
            'slug.regex' => 'الرابط المختصر يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات فقط.',
            'description.string' => 'وصف القسم يجب أن يكون نصًا.',
            'image.string' => 'مسار الصورة يجب أن يكون نصًا.',
            'image.max' => 'مسار الصورة يجب ألا يتجاوز 255 حرفًا.',

            'description.max' => 'وصف القسم يجب ألا يتجاوز 2000 حرف.',

            'sort_order.integer' => 'ترتيب القسم يجب أن يكون رقمًا صحيحًا.',

            'sort_order.min' => 'ترتيب القسم لا يمكن أن يكون أقل من صفر.',
            'sort_order.max' => 'ترتيب القسم يجب ألا يتجاوز 2147483647.',

            'is_active.boolean' => 'حالة القسم غير صحيحة.',
        ];
    }
}
