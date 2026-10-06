<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['branch_uuid' => ['sometimes', 'uuid'], 'product_uuid' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::enum(StockMovementType::class)], 'reference_uuid' => ['sometimes', 'uuid'],
            'reference_type' => ['sometimes', 'string', 'max:40'], 'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
