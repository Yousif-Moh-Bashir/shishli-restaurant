<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'product_uuid' => ['required', 'uuid', Rule::exists('products', 'uuid')->whereNull('deleted_at')->where('tracks_inventory', true)],
            'opening_balance' => ['sometimes', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'on_hand' => ['prohibited'], 'reserved' => ['prohibited'], 'available' => ['prohibited']];
    }
}
