<?php

namespace App\Http\Requests\Api\V1;

class UpdateCustomerAddressRequest extends StoreCustomerAddressRequest
{
    public function rules(): array
    {
        return array_map(fn (array $rules): array => array_merge(['sometimes'], $rules), parent::rules());
    }
}
