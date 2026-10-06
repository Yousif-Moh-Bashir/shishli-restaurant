<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OperationsReportService
{
    public function __construct(private ReportQuery $queries) {}

    public function deliverySummary(ReportFilterData $filter): array
    {
        $row = $this->queries->orders($filter)->selectRaw("COUNT(CASE WHEN orders.type = 'delivery' THEN 1 END) AS delivery_orders_count,
            COUNT(CASE WHEN orders.type = 'pickup' THEN 1 END) AS pickup_orders_count,
            COUNT(CASE WHEN orders.type = 'delivery' AND orders.status = 'completed' AND orders.delivery_fee = 0 THEN 1 END) AS free_delivery_orders_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.type = 'delivery' AND orders.status = 'completed' THEN ".ReportMath::cents('orders.delivery_fee').' ELSE 0 END), 0) AS delivery_fees_collected_minor')->first();

        return ReportMath::row($row);
    }

    public function zones(ReportFilterData $filter): Builder
    {
        return $this->queries->completed($filter)->where('orders.type', 'delivery')
            ->leftJoin('order_addresses AS addresses', 'addresses.order_id', '=', 'orders.id')
            ->select('addresses.delivery_zone_uuid AS zone_uuid', 'addresses.delivery_zone_name AS zone_name')
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM('.ReportMath::cents('orders.delivery_fee').'), 0) AS delivery_fees_minor')
            ->groupBy('addresses.delivery_zone_uuid', 'addresses.delivery_zone_name')->orderByDesc('orders_count')->orderBy('zone_uuid')->orderBy('zone_name');
    }

    public function statuses(ReportFilterData $filter): Builder
    {
        return $this->queries->orders($filter)->select('orders.status')->selectRaw('COUNT(*) AS orders_count')->groupBy('orders.status')->orderBy('orders.status');
    }

    public function timings(ReportFilterData $filter): array
    {
        $duration = DB::connection()->getDriverName() === 'mysql'
            ? 'TIMESTAMPDIFF(SECOND, orders.preparing_at, orders.ready_at)'
            : "(strftime('%s', orders.ready_at) - strftime('%s', orders.preparing_at))";
        $row = $this->queries->orders($filter)->whereNotNull('orders.preparing_at')->whereNotNull('orders.ready_at')
            ->whereColumn('orders.ready_at', '>=', 'orders.preparing_at')
            ->selectRaw("COUNT(*) AS sampled_orders_count, COALESCE(SUM({$duration}), 0) AS seconds")->first();

        return ['sampled_orders_count' => (int) $row->sampled_orders_count,
            'average_preparation_minutes' => ReportMath::decimal(ReportMath::ratio((int) $row->seconds * 100, (int) $row->sampled_orders_count * 60))];
    }

    public function cancellations(ReportFilterData $filter): Builder
    {
        return $this->queries->orders($filter)->where('orders.status', 'cancelled')->select('orders.cancellation_reason')
            ->selectRaw('COUNT(*) AS cancelled_orders_count, COALESCE(SUM('.ReportMath::cents('orders.total').'), 0) AS cancelled_sales_value_minor')
            ->groupBy('orders.cancellation_reason')->orderByDesc('cancelled_orders_count')->orderBy('orders.cancellation_reason');
    }
}
