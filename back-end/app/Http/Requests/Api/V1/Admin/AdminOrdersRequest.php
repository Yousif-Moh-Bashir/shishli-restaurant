<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\PaymentMethod;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use Illuminate\Validation\Rule;

class AdminOrdersRequest extends ListOrdersRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['period' => ['sometimes', Rule::in(['current', 'history', 'range'])],
            'date_from' => ['required_if:period,range', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,range', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'branch_uuid' => ['sometimes', 'uuid'], 'customer_uuid' => ['sometimes', 'uuid'], 'order_number' => ['sometimes', 'string', 'max:150'], 'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)], 'sort' => ['sometimes', Rule::in(['placed_at', 'created_at', 'order_number', 'status', 'total'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])]]);
    }
}
