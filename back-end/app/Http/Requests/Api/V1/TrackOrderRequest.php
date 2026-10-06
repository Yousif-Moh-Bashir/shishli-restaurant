<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TrackOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:80'], 'token' => ['required', 'string', 'size:64', 'regex:/^[a-zA-Z0-9]{64}$/D']];
    }

    protected function prepareForValidation(): void
    {
        // Credentials must be in the POST body; query strings are never accepted.
        $body = $this->isJson() ? $this->json()->all() : $this->request->all();
        $this->replace(['order_number' => $body['order_number'] ?? null, 'token' => $body['token'] ?? null]);
    }
}
