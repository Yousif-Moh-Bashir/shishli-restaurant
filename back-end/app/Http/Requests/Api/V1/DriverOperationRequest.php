<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('driver') && $this->user()?->can('driver.access');
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'availability' => ['available' => ['required', 'boolean']],
            'transition' => ['status' => ['required', Rule::enum(DeliveryStatus::class)], 'reason' => ['required_if:status,failed', 'nullable', 'string', 'min:3', 'max:1000']],
            'collect' => ['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99']],
            default => [],
        };
    }
}
