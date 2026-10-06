<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared scope boundary for API and exports. No staff-to-branch association exists yet. */
class ReportQuery
{
    public function orders(ReportFilterData $filter, bool $period = true): Builder
    {
        $query = DB::table('orders');
        if ($period) {
            $query->where('orders.placed_at', '>=', $filter->from)->where('orders.placed_at', '<', $filter->until);
        }
        if ($filter->branchUuid !== null) {
            $query->whereIn('orders.branch_id', DB::table('branches')->select('id')->where('uuid', $filter->branchUuid));
        }
        foreach (['type' => $filter->orderType, 'status' => $filter->orderStatus, 'payment_method' => $filter->paymentMethod] as $column => $value) {
            if ($value !== null) {
                $query->where('orders.'.$column, $value);
            }
        }

        return $query;
    }

    public function completed(ReportFilterData $filter): Builder
    {
        return $this->orders($filter)->where('orders.status', OrderStatus::Completed->value);
    }

    public function summaryQuery(ReportFilterData $filter): Builder
    {
        $query = $this->orders($filter)->selectRaw('COUNT(*) AS orders_count, MAX(orders.placed_at) AS last_order_at');
        foreach (OrderStatus::cases() as $status) {
            $query->selectRaw('COUNT(CASE WHEN orders.status = ? THEN 1 END) AS '.$status->value.'_orders_count', [$status->value]);
        }

        return $query->selectRaw('COALESCE(SUM(CASE WHEN orders.status = ? THEN '.ReportMath::cents('orders.total').' ELSE 0 END), 0) AS completed_sales_minor', ['completed'])
            ->selectRaw("COUNT(CASE WHEN orders.user_id IS NULL THEN 1 END) AS guest_orders_count,
                COUNT(CASE WHEN orders.type = 'delivery' THEN 1 END) AS delivery_orders_count,
                COUNT(CASE WHEN orders.type = 'pickup' THEN 1 END) AS pickup_orders_count");
    }

    public function summary(ReportFilterData $filter): array
    {
        $row = ReportMath::row($this->summaryQuery($filter)->first());
        $row['completion_rate'] = ReportMath::decimal(ReportMath::ratio($row['completed_orders_count'] * 10000, $row['orders_count']));
        $row['cancellation_rate'] = ReportMath::decimal(ReportMath::ratio($row['cancelled_orders_count'] * 10000, $row['orders_count']));

        return $row;
    }

    public function page(Builder $query, ReportFilterData $filter): array
    {
        $page = $query->paginate($filter->perPage, ['*'], 'page', $filter->page);

        return ['data' => $page->getCollection()->map(fn (object $row): array => ReportMath::row($row))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}
