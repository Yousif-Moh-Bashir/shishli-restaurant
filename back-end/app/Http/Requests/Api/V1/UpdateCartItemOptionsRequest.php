<?php

namespace App\Http\Requests\Api\V1;

class UpdateCartItemOptionsRequest extends AddCartItemRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['product_uuid'], $rules['quantity']);
        $rules['options'] = ['present', 'array', 'max:100'];

        return $rules;
    }
}
