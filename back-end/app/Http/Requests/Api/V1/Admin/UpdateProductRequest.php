<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('products', 'slug')->ignore($this->route('product'))];
        $rules['sku'] = ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->route('product'))];

        foreach ($rules as &$fieldRules) {
            if (! in_array('sometimes', $fieldRules, true)) {
                array_unshift($fieldRules, 'sometimes');
            }
        }

        return $rules;
    }
}
