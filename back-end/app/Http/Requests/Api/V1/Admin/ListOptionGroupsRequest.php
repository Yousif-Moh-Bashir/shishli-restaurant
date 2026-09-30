<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OptionGroupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOptionGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::enum(OptionGroupType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return ['search.string' => 'عبارة البحث يجب أن تكون نصًا.', 'search.max' => 'عبارة البحث طويلة جدًا.',
            'is_active.boolean' => 'حالة التفعيل غير صحيحة.', 'type.enum' => 'نوع المجموعة غير صحيح.',
            'integer' => 'القيمة يجب أن تكون عددًا صحيحًا.', 'min' => 'القيمة أقل من الحد المسموح.',
            'max' => 'القيمة تتجاوز الحد المسموح.'];
    }
}
