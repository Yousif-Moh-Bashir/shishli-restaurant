<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOptionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('option_values', 'slug')->where('option_group_id', $this->route('optionGroup')->id)],
            'price_modifier' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute مطلوب.', 'string' => ':attribute يجب أن يكون نصًا.',
            'numeric' => ':attribute يجب أن يكون رقمًا.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.',
            'boolean' => 'قيمة :attribute غير صحيحة.', 'min' => ':attribute يجب ألا يقل عن :min.',
            'max' => ':attribute يتجاوز الحد المسموح (:max).',
            'slug.unique' => 'الرابط المختصر مستخدم مسبقًا داخل هذه المجموعة.',
            'slug.regex' => 'الرابط المختصر يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات فقط.',
            'price_modifier.decimal' => 'تعديل السعر يجب ألا يتجاوز منزلتين عشريتين.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'اسم الخيار', 'slug' => 'الرابط المختصر', 'price_modifier' => 'تعديل السعر',
            'is_default' => 'الخيار الافتراضي', 'is_active' => 'حالة التفعيل', 'sort_order' => 'الترتيب'];
    }
}
