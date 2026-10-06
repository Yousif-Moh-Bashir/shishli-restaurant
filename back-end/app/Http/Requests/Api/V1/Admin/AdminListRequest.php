<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Admin\AdminAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route()?->getName() ?? '';
        $sorts = match (true) {
            str_contains($route, 'coupons') => ['code', 'created_at', 'starts_at', 'ends_at', 'used_count'],
            str_contains($route, 'staff') => ['name', 'created_at', 'updated_at', 'is_active'],str_contains($route, 'categories') => ['name', 'sort_order', 'created_at'],$this->is('*/admin/branches') => ['name', 'sort_order', 'created_at'],str_contains($route, 'activity-logs') => ['created_at'],str_contains($route, 'pages') => ['key', 'title', 'created_at', 'updated_at'],default => ['created_at', 'sort_order', 'title', 'updated_at']
        };

        return ['search' => ['sometimes', 'string', 'max:150'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in($sorts)], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'active' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'], 'role' => ['sometimes', Rule::in(AdminAccessService::STAFF_ROLES)],
            'user_uuid' => ['sometimes', 'uuid'], 'action' => ['sometimes', 'string', 'max:150'], 'subject_type' => ['sometimes', 'string', 'max:80'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), ['after_or_equal:date_from'])]];
    }
}
