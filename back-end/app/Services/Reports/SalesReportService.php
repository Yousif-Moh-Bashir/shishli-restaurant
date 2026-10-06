<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    public function __construct(private ReportQuery $queries) {}

    /** Small calendar buckets only; individual orders never enter PHP. Weeks start Monday. */
    public function trend(ReportFilterData $filter): array
    {
        $start = $filter->from->setTimezone($filter->timezone);
        $end = $filter->until->setTimezone($filter->timezone);
        $cursor = match ($filter->groupBy) {
            'week' => $start->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $start->startOfMonth(),
            default => $start,
        };
        $buckets = [];
        $bindings = [];
        $expression = 'CASE';
        while ($cursor < $end) {
            $next = match ($filter->groupBy) {
                'week' => $cursor->addWeek(), 'month' => $cursor->addMonth(), default => $cursor->addDay(),
            };
            $key = $cursor->toDateString();
            $expression .= ' WHEN orders.placed_at < ? THEN ?';
            array_push($bindings, $next->utc()->toDateTimeString(), $key);
            $buckets[$key] = ['period' => $key, 'orders_count' => 0, 'completed_orders_count' => 0,
                'completed_sales' => '0.00', 'average_order_value' => '0.00'];
            $cursor = $next;
        }
        $expression .= ' END';
        $rows = $this->queries->summaryQuery($filter)->selectRaw($expression.' AS period', $bindings)->groupBy('period')->get();
        foreach ($rows as $row) {
            $metrics = ReportMath::row($row);
            $buckets[$row->period] = array_intersect_key($metrics, $buckets[$row->period]);
        }

        return array_values($buckets);
    }

    public function branches(ReportFilterData $filter): Builder
    {
        $aggregates = $this->queries->summaryQuery($filter)->addSelect('orders.branch_id')->groupBy('orders.branch_id');
        $query = DB::table('branches')->leftJoinSub($aggregates, 'totals', 'totals.branch_id', '=', 'branches.id')
            ->select('branches.uuid AS branch_uuid', 'branches.name AS branch_name')
            ->selectRaw('COALESCE(totals.orders_count, 0) AS orders_count,
                COALESCE(totals.completed_orders_count, 0) AS completed_orders,
                COALESCE(totals.cancelled_orders_count, 0) AS cancelled_orders,
                COALESCE(totals.completed_sales_minor, 0) AS completed_sales_minor,
                COALESCE(totals.delivery_orders_count, 0) AS delivery_orders,
                COALESCE(totals.pickup_orders_count, 0) AS pickup_orders');
        if ($filter->branchUuid !== null) {
            $query->where('branches.uuid', $filter->branchUuid);
        }

        return $query->orderByDesc('completed_sales_minor')->orderBy('branches.uuid');
    }
}
