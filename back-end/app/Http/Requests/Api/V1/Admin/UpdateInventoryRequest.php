<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'is_tracking' => ['sometimes', 'boolean'], 'on_hand' => ['prohibited'], 'reserved' => ['prohibited'],
            'available' => ['prohibited'], 'branch_uuid' => ['prohibited'], 'product_uuid' => ['prohibited'],
            'opening_balance' => ['prohibited']];
    }
}
