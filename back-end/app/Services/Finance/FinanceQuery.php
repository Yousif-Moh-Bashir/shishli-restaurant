<?php

namespace App\Services\Finance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FinanceQuery
{
    public function __construct(private FinanceAccess $access) {}

    public function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'branch_uuid' => ['nullable', 'uuid'],
            'source_uuid' => ['nullable', 'uuid', 'exists:finance_revenue_sources,uuid'],
            'status' => ['nullable', 'in:draft,approved'],
            'group_by' => ['nullable', 'in:day,month,year'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ], ValidationMessages::messages(), ValidationMessages::attributes());
    }

    public function apply(Builder $query, Request $request, array $filters): Builder
    {
        $this->access->scope($query, $request->user());
        if (! empty($filters['branch_uuid'])) {
            $query->where('branch_id', $this->access->branch($request->user(), $filters['branch_uuid'])->id);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }

        return $query;
    }
}
