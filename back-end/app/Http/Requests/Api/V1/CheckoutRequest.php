<?php

namespace App\Http\Requests\Api\V1;

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
            'type' => ['required', Rule::enum(OrderType::class)],
            'customer' => ['required', 'array:name,phone,email'],
            'customer.name' => ['required', 'string', 'max:150'],
            'customer.phone' => ['required', 'string', 'max:20'],
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
}
