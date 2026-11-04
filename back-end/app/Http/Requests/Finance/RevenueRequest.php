<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class RevenueRequest extends FinanceRequest
{
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }
        foreach ($lines as &$line) {
            if (is_array($line) && (! array_key_exists('amount', $line) || $line['amount'] === null || $line['amount'] === '')) {
                $line['amount'] = '0.00';
            }
        }
        unset($line);
        $this->merge(['lines' => $lines]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('finance.revenues.'.($this->isMethod('POST') && ! $this->route('uuid') ? 'create' : ($this->routeIs('finance.revenues.correct') ? 'correct' : 'edit'))) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('finance.timezone'))->toDateString()],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array:source_uuid,amount'],
            'lines.*.source_uuid' => ['required', 'uuid', 'distinct', 'exists:finance_revenue_sources,uuid'],
            'lines.*.amount' => ['required', Money::RULE],
            'version' => [$this->route('uuid') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'reason' => [$this->routeIs('finance.revenues.correct') ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
