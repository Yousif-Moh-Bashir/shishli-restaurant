<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'subtotal' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'address' => ['required', 'array:city,district,latitude,longitude'],
            'address.city' => ['required', 'string', 'max:100'], 'address.district' => ['required', 'string', 'max:100'],
            'address.latitude' => ['nullable', 'required_with:address.longitude', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'required_with:address.latitude', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'الحقل :attribute مطلوب.', 'required_with' => 'يجب إدخال خط العرض وخط الطول معًا.',
            'uuid' => 'المعرف غير صحيح.', 'exists' => 'الفرع غير موجود.', 'array' => 'بيانات العنوان غير صحيحة.',
            'numeric' => 'الحقل :attribute يجب أن يكون رقمًا.', 'between' => 'الإحداثيات خارج النطاق المسموح.',
            'min' => 'القيمة أقل من الحد المسموح.', 'max' => 'القيمة تتجاوز الحد المسموح.',
            'regex' => 'المبلغ يجب أن يحتوي منزلتين عشريتين كحد أقصى.', 'string' => 'الحقل :attribute يجب أن يكون نصًا.'];
    }
}
