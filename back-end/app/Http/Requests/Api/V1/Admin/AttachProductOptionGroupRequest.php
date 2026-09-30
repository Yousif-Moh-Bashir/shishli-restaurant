<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachProductOptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'option_group_uuid' => ['required', 'uuid', Rule::exists('option_groups', 'uuid')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_required_override' => ['nullable', 'boolean'],
            'min_select_override' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'max_select_override' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'option_group_uuid.required' => 'مجموعة الخيارات مطلوبة.',
            'option_group_uuid.uuid' => 'معرف المجموعة غير صحيح.',
            'option_group_uuid.exists' => 'مجموعة الخيارات المحددة غير موجودة.',
            'integer' => ':attribute يجب أن يكون عددًا صحيحًا.',
            'min' => ':attribute يجب ألا يقل عن :min.', 'max' => ':attribute يتجاوز الحد المسموح.',
            'boolean' => 'قيمة :attribute غير صحيحة.',
        ];
    }

    public function attributes(): array
    {
        return ['sort_order' => 'الترتيب', 'is_required_override' => 'إلزامية الاختيار',
            'min_select_override' => 'الحد الأدنى', 'max_select_override' => 'الحد الأقصى'];
    }
}
