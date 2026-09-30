<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OptionGroupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('option_groups', 'slug')],
            'type' => ['required', Rule::enum(OptionGroupType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute مطلوب.',
            'string' => ':attribute يجب أن يكون نصًا.',
            'integer' => ':attribute يجب أن يكون عددًا صحيحًا.',
            'boolean' => 'قيمة :attribute غير صحيحة.',
            'min' => ':attribute يجب ألا يقل عن :min.',
            'max' => ':attribute يتجاوز الحد المسموح (:max).',
            'slug.unique' => 'الرابط المختصر مستخدم مسبقًا.',
            'slug.regex' => 'الرابط المختصر يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات فقط.',
            'type.enum' => 'نوع المجموعة يجب أن يكون single أو multiple.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم المجموعة', 'slug' => 'الرابط المختصر', 'type' => 'نوع المجموعة',
            'is_required' => 'إلزامية الاختيار', 'min_select' => 'الحد الأدنى', 'max_select' => 'الحد الأقصى',
            'sort_order' => 'الترتيب', 'is_active' => 'حالة التفعيل',
        ];
    }
}
