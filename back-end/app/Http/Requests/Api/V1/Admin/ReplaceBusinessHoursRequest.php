<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\DayOfWeek;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.hours.update') ?? false;
    }

    public function rules(): array
    {
        return ['hours' => ['present', 'array', 'max:100'], 'hours.*' => ['array:day_of_week,opens_at,closes_at'],
            'hours.*.day_of_week' => ['required', 'integer', Rule::enum(DayOfWeek::class)],
            'hours.*.opens_at' => ['required', 'date_format:H:i'], 'hours.*.closes_at' => ['required', 'date_format:H:i']];
    }
}
