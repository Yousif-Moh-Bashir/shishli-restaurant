<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ClaimGuestOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->phone_verified_at !== null;
    }

    public function rules(): array
    {
        return ['phone' => ['prohibited'], 'phone_normalized' => ['prohibited'],
            'customer_phone_normalized' => ['prohibited'], 'user_uuid' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
