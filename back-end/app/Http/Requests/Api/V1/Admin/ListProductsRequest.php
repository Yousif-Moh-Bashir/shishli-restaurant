<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ListProductsRequest as PublicListProductsRequest;

class ListProductsRequest extends PublicListProductsRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['branch'], $rules['featured'], $rules['available']);

        return [
            ...$rules,
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
