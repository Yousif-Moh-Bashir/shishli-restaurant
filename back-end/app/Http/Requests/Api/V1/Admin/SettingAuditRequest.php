<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\RestaurantSettingDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.audit.view') ?? false;
    }

    public function rules(): array
    {
        return ['key' => ['sometimes', Rule::in(array_keys(app(RestaurantSettingDefinition::class)->all()))],
            'user_uuid' => ['sometimes', 'uuid'], 'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
