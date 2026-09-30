<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_uuid' => ['required', 'uuid', Rule::exists('categories', 'uuid')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('products', 'slug')],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'preparation_time' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_uuid.required' => 'القسم مطلوب.',
            'category_uuid.uuid' => 'معرف القسم غير صحيح.',
            'category_uuid.exists' => 'القسم المحدد غير موجود.',
            'name.required' => 'اسم المنتج مطلوب.',
            'name.string' => 'اسم المنتج يجب أن يكون نصًا.',
            'name.max' => 'اسم المنتج يجب ألا يتجاوز 180 حرفًا.',
            'slug.string' => 'الرابط المختصر يجب أن يكون نصًا.',
            'slug.max' => 'الرابط المختصر يجب ألا يتجاوز 200 حرف.',
            'slug.regex' => 'الرابط المختصر يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات فقط.',
            'slug.unique' => 'الرابط المختصر مستخدم مسبقًا.',
            'sku.string' => 'رمز المنتج يجب أن يكون نصًا.',
            'sku.max' => 'رمز المنتج يجب ألا يتجاوز 100 حرف.',
            'sku.unique' => 'رمز المنتج مستخدم مسبقًا.',
            'short_description.string' => 'الوصف المختصر يجب أن يكون نصًا.',
            'short_description.max' => 'الوصف المختصر يجب ألا يتجاوز 500 حرف.',
            'description.string' => 'وصف المنتج يجب أن يكون نصًا.',
            'description.max' => 'وصف المنتج يجب ألا يتجاوز 5000 حرف.',
            'base_price.required' => 'سعر المنتج مطلوب.',
            'base_price.numeric' => 'سعر المنتج يجب أن يكون رقمًا.',
            'base_price.min' => 'سعر المنتج لا يمكن أن يكون أقل من صفر.',
            'base_price.max' => 'سعر المنتج يجب ألا يتجاوز 99999999.99.',
            'base_price.decimal' => 'سعر المنتج يجب ألا يتجاوز منزلتين عشريتين.',
            'is_active.boolean' => 'حالة تفعيل المنتج غير صحيحة.',
            'is_available.boolean' => 'حالة توفر المنتج غير صحيحة.',
            'is_featured.boolean' => 'حالة تمييز المنتج غير صحيحة.',
            'sort_order.integer' => 'ترتيب المنتج يجب أن يكون رقمًا صحيحًا.',
            'sort_order.min' => 'ترتيب المنتج لا يمكن أن يكون أقل من صفر.',
            'sort_order.max' => 'ترتيب المنتج يجب ألا يتجاوز 2147483647.',
            'preparation_time.integer' => 'وقت التحضير يجب أن يكون عددًا صحيحًا من الدقائق.',
            'preparation_time.min' => 'وقت التحضير لا يمكن أن يكون أقل من صفر.',
            'preparation_time.max' => 'وقت التحضير يجب ألا يتجاوز 1440 دقيقة.',
        ];
    }
}
