<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['nullable', 'string', 'min:1', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'card' => ['prohibited'], 'card_number' => ['prohibited'], 'pan' => ['prohibited'],
            'cvv' => ['prohibited'], 'cvc' => ['prohibited'], 'payment_token' => ['prohibited']];
    }
}
