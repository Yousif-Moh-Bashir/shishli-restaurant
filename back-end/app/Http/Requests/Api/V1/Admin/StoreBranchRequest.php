<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supports_pickup' => ['sometimes', 'boolean'],
            'supports_delivery' => ['sometimes', 'boolean'],
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:branches,slug',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            'whatsapp' => [
                'nullable',
                'string',
                'max:20',
            ],

            'city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
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

            'latitude.numeric' => 'خط العرض يجب أن يكون رقمًا.',
            'latitude.between' => 'خط العرض غير صحيح.',

            'longitude.numeric' => 'خط الطول يجب أن يكون رقمًا.',
            'longitude.between' => 'خط الطول غير صحيح.',

            'is_active.boolean' => 'حالة الفرع غير صحيحة.',
            'accepts_orders.boolean' => 'حالة استقبال الطلبات غير صحيحة.',

            'sort_order.integer' => 'ترتيب الفرع يجب أن يكون رقمًا صحيحًا.',
            'sort_order.min' => 'ترتيب الفرع لا يمكن أن يكون أقل من صفر.',
        ];
    }
}
