<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StockMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $operation = $this->route()->getActionMethod();
        $rules = ['reason' => [in_array($operation, ['adjust', 'waste', 'returnStock'], true) ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
            'reference_uuid' => ['sometimes', 'nullable', 'uuid'], 'on_hand' => ['prohibited'], 'reserved' => ['prohibited']];
        if ($operation !== 'returnStock') {
            $rules['quantity'] = ['required', 'numeric', $operation === 'adjust' ? 'min:-999999999.999' : 'gt:0', 'max:999999999.999', 'decimal:0,3'];
            if ($operation === 'adjust') {
                $rules['quantity'][] = 'not_in:0,0.0,0.00,0.000';
            }
        }

        return $rules;
    }
}
