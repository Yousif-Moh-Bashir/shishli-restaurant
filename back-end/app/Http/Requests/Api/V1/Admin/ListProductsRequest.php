<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ListProductsRequest as PublicListProductsRequest;
use Illuminate\Validation\Rule;

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
            'category_uuid' => ['sometimes', 'uuid'],
            'branch_uuid' => ['sometimes', 'uuid'],
            'active' => ['sometimes', 'boolean'],
            'available' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'tracks_inventory' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['default', 'price_asc', 'price_desc', 'name', 'latest', 'base_price', 'created_at', 'updated_at', 'sku', 'sort_order'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
