<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DriverSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('drivers.settle_cash') ?? false;
    }

    public function rules(): array
    {
        return ['collection_uuids' => ['required', 'array', 'min:1', 'max:100'], 'collection_uuids.*' => ['required', 'uuid', 'distinct'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
