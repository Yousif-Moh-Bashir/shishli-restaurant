<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrderTiming;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'order_timing' => ['sometimes', Rule::enum(OrderTiming::class)],
            'scheduled_for' => ['nullable', 'date', 'required_if:order_timing,scheduled', 'prohibited_unless:order_timing,scheduled',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'type' => ['required', Rule::enum(OrderType::class)],
            'customer' => ['required', 'array:name,phone,email'],
            'customer.name' => ['required', 'string', 'min:2', 'max:150'],
            'customer.phone' => ['required', 'string', 'max:13', 'regex:/^(?:05[0-9]{8}|(?:\+966|966)5[0-9]{8})$/D'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'address_uuid' => ['exclude_unless:type,delivery', 'required_without:address', 'prohibits:address', 'uuid'],
            'address' => ['exclude_unless:type,delivery', 'required_without:address_uuid', 'prohibits:address_uuid', 'array:recipient_name,phone,city,district,street,building_number,floor,apartment,landmark,notes,latitude,longitude'],
        ];
        foreach ((new StoreCustomerAddressRequest)->rules() as $field => $fieldRules) {
            if (in_array($field, ['label', 'is_default'], true)) {
                continue;
            }
            $rules['address.'.$field] = array_merge(['exclude_unless:type,delivery'], array_map(
                fn (string $rule): string => $rule === 'required' ? 'required_with:address' : $rule, $fieldRules));
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'customer.name.required' => 'اسم العميل مطلوب.',
            'customer.name.min' => 'أدخل اسمًا من حرفين على الأقل.',
            'customer.phone.required' => 'رقم الجوال مطلوب.',
            'customer.phone.regex' => 'أدخل رقم جوال سعودي صحيح مثل 0551234567 أو +966551234567.',
            'customer.phone.max' => 'رقم الجوال أطول من الصيغة المسموحة.',
        ];
    }
}
