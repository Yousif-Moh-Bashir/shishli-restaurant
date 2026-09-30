<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class UpdateOptionGroupRequest extends StoreOptionGroupRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('option_groups', 'slug')->ignore($this->route('optionGroup'))];
        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }
}
