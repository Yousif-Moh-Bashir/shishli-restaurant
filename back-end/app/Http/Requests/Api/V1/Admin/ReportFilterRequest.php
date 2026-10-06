<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Services\Reports\ReportFilterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])],
            'branch_uuid' => ['nullable', 'uuid', Rule::exists('branches', 'uuid')],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'order_status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'timezone' => ['missing'], 'group_by' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'sort_by' => ['sometimes', Rule::in(['quantity', 'net_sales', 'orders_count'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'format' => ['sometimes', Rule::in(['csv'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $filter = ReportFilterData::fromArray($validator->validated(), $this->routeIs('api.v1.admin.dashboard'));
            $days = $filter->from->setTimezone($filter->timezone)->diffInDays($filter->until->setTimezone($filter->timezone));
            if ($days <= 0 || $days > config('business.report_max_days')) {
                $validator->errors()->add('date_to', 'Report periods must contain between 1 and 366 calendar days.');
            }
        }];
    }

    public function filters(): ReportFilterData
    {
        return ReportFilterData::fromArray($this->validated(), $this->routeIs('api.v1.admin.dashboard'));
    }
}
