<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:150'];
        $rules['slug'] = ['sometimes', 'nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('categories', 'slug')->ignore($this->route('category'))];

        foreach (['parent_uuid', 'description', 'image'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
