<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'scope' => ['sometimes', Rule::in(['available', 'mine'])], 'settled' => ['sometimes', 'boolean'],
            'period' => ['sometimes', Rule::in(['current', 'history'])],
            'work_date' => ['nullable', 'date_format:Y-m-d', 'prohibited_unless:period,history'],
            'search' => ['sometimes', 'string', 'max:150'], 'branch_uuid' => ['sometimes', 'uuid'], 'available' => ['sometimes', 'boolean']];
    }
}
