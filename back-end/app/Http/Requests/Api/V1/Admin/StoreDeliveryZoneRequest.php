<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\DeliveryZoneWriter;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return DeliveryZoneWriter::rules();
    }

    public function messages(): array
    {
        return DeliveryZoneWriter::messages();
    }
}
