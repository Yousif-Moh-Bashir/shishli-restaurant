<?php

namespace App\Http\Requests\Api\V1;

class UpdateCartItemQuantityRequest extends AddCartItemRequest
{
    public function rules(): array
    {
        return ['quantity' => parent::rules()['quantity']];
    }
}
