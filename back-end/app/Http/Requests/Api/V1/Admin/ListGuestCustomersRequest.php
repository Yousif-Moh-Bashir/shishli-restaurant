<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ListOrdersRequest;

class ListGuestCustomersRequest extends ListOrdersRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customers.view') ?? false;
    }

    public function rules(): array
    {
        return array_merge($this->routeIs('api.v1.admin.guest-customers.orders') ? parent::rules() : [], ['phone' => [$this->routeIs('api.v1.admin.guest-customers.orders') ? 'required' : 'sometimes', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
    }
}
