<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'], 'district' => ['required', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:200'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:50'], 'apartment' => ['nullable', 'string', 'max:50'],
            'landmark' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'الحقل :attribute مطلوب.', 'string' => 'الحقل :attribute يجب أن يكون نصًا.',
            'max' => 'الحقل :attribute يتجاوز الحد المسموح (:max).', 'numeric' => 'الحقل :attribute يجب أن يكون رقمًا.',
            'between' => 'قيمة :attribute خارج النطاق المسموح.', 'boolean' => 'قيمة :attribute غير صحيحة.'];
    }
}
