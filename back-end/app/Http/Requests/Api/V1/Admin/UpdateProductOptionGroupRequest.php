<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdateProductOptionGroupRequest extends AttachProductOptionGroupRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['option_group_uuid']);
        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }
}
