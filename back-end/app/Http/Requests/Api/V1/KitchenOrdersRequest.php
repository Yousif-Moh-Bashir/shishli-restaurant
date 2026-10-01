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
            'type' => ['sometimes', Rule::enum(OrderType::class)], 'branch' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
