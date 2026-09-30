<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class UpdateOptionValueRequest extends StoreOptionValueRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('option_values', 'slug')->where('option_group_id', $this->route('optionGroup')->id)->ignore($this->route('optionValue'))];
        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }
}
