<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['branch_uuid' => ['sometimes', 'uuid', Rule::exists('branches', 'uuid')],
            'product_uuid' => ['sometimes', 'uuid', Rule::exists('products', 'uuid')],
            'category_uuid' => ['sometimes', 'uuid', Rule::exists('categories', 'uuid')],
            'tracking' => ['sometimes', 'boolean'], 'is_tracking' => ['sometimes', 'boolean'], 'low_stock' => ['sometimes', 'boolean'],
            'out_of_stock' => ['sometimes', 'boolean'], 'search' => ['sometimes', 'string', 'max:180'],
            'sort' => ['sometimes', Rule::in(['product_name', 'available', 'on_hand', 'updated_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
