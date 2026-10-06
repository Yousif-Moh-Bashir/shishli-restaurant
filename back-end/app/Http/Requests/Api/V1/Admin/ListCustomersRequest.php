<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customers.view') ?? false;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'string', 'max:150'], 'phone' => ['sometimes', 'string', 'max:40'],
            'active' => ['sometimes', 'boolean'], 'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'email' => ['sometimes', 'email', 'max:255'], 'is_active' => ['sometimes', 'boolean'], 'has_orders' => ['sometimes', 'boolean'],
            'registered_from' => ['sometimes', 'date_format:Y-m-d'],
            'registered_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('registered_from'), ['after_or_equal:registered_from'])],
            'sort' => ['sometimes', Rule::in(['created_at', 'name', 'orders_count', 'total_spent', 'last_order_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
