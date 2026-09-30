<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return [
            'supports_pickup' => ['sometimes', 'boolean'],
            'supports_delivery' => ['sometimes', 'boolean'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('branches', 'slug')
                    ->ignore($branch?->id),
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],

            'whatsapp' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'latitude' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'accepts_orders' => [
                'sometimes',
                'boolean',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:4294967295',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supports_pickup.boolean' => 'حالة دعم الاستلام غير صحيحة.',
            'supports_delivery.boolean' => 'حالة دعم التوصيل غير صحيحة.',
            'name.required' => 'اسم الفرع مطلوب.',
            'name.max' => 'اسم الفرع يجب ألا يتجاوز 150 حرفًا.',

            'slug.unique' => 'الرابط المختصر مستخدم مسبقًا.',

            'latitude.between' => 'خط العرض غير صحيح.',
            'longitude.between' => 'خط الطول غير صحيح.',

            'sort_order.integer' => 'ترتيب الفرع يجب أن يكون رقمًا صحيحًا.',
            'sort_order.min' => 'ترتيب الفرع لا يمكن أن يكون أقل من صفر.',
        ];
    }
}
