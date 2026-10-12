<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReassignDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('drivers.reassign') ?? false;
    }

    public function rules(): array
    {
        return ['driver_uuid' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
