<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdateBranchProductRequest extends AttachBranchProductRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['product_uuid']);
        $rules['price_override'] = ['sometimes', ...$rules['price_override']];

        return $rules;
    }
}
