<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KitchenOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', Rule::in(['confirmed', 'preparing', 'ready'])],
            'period' => ['sometimes', Rule::in(['current', 'history', 'range'])],
            'date_from' => ['required_if:period,range', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,range', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'type' => ['sometimes', Rule::enum(OrderType::class)], 'branch' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
