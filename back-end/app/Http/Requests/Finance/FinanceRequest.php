<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\ValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

abstract class FinanceRequest extends FormRequest
{
    public function messages(): array
    {
        return ValidationMessages::messages();
    }

    public function attributes(): array
    {
        return ValidationMessages::attributes();
    }
}
