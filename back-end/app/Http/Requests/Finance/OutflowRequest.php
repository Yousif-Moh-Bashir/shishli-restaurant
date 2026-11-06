<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class OutflowRequest extends FinanceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('finance.'.$this->route('kind').'.'.($this->route('uuid') ? 'edit' : 'create')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $purchase = $this->route('kind') === 'purchases';

        return [
            'branch_uuid' => ['required', 'uuid', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('finance.timezone'))->toDateString()],
            'amount' => ['required', Money::RULE, 'numeric', 'gt:0'],
            'description' => [$purchase ? 'nullable' : 'required', 'string', 'max:5000'],
            'payment_method' => ['required', Rule::in(['cash', 'network', 'transfer'])],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'],
            'category_uuid' => [$purchase ? 'prohibited' : 'required', 'uuid', 'exists:finance_expense_categories,uuid'],
            'supplier_uuid' => [$purchase ? 'nullable' : 'prohibited', 'uuid', 'exists:finance_suppliers,uuid'],
            'invoice_number' => [$purchase ? 'nullable' : 'prohibited', 'string', 'max:100'],
            'version' => [$this->route('uuid') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'reason' => [$this->route('uuid') ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
