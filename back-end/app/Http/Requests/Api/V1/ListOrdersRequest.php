<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:150'], 'branch' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)], 'type' => ['sometimes', Rule::enum(OrderType::class)],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
