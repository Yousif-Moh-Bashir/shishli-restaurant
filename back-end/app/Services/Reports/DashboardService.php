<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;

class DashboardService
{
    public function __construct(private ReportQuery $queries, private PaymentReportService $payments, private AudienceReportService $customers) {}

    public function summary(ReportFilterData $filter, bool $financial): array
    {
        $summary = $this->queries->summary($filter);
        $summary['new_customers_count'] = $this->customers->newCustomers($filter);
        if ($financial) {
            $summary['collected_revenue'] = $this->payments->collected($filter);
        }

        return $summary;
    }

    public function get(ReportFilterData $filter, bool $financial, bool $inventory = false, bool $sales = true): array
    {
        $current = $this->summary($filter, $financial);
        if ($inventory) {
            $current['inventory'] = app(InventoryReportService::class)->get(array_filter(['branch_uuid' => $filter->branchUuid]));
        }
        $previousFilter = $filter->previous();
        $previous = $this->summary($previousFilter, $financial);
        if (! $sales) {
            foreach (['completed_sales_minor', 'completed_sales', 'average_order_value', 'average_order_value_minor'] as $key) {
                unset($current[$key], $previous[$key]);
            }
        }
        $changes = [];
        foreach (['orders_count', 'completed_orders_count', 'completed_sales', 'average_order_value', 'collected_revenue'] as $metric) {
            if (! array_key_exists($metric, $current)) {
                continue;
            }
            $asMinor = fn (int|string $value): int => is_int($value) ? $value : (int) str_replace('.', '', $value);
            $changes[$metric] = ['current' => $current[$metric], 'previous' => $previous[$metric],
                ...ReportMath::comparison($asMinor($current[$metric]), $asMinor($previous[$metric]))];
        }
        $statuses = [];
        foreach (OrderStatus::cases() as $status) {
            $statuses[$status->value] = $current[$status->value.'_orders_count'];
        }

        return ['period' => $filter->period(), 'summary' => $current, 'order_statuses' => $statuses,
            'order_types' => ['delivery' => $current['delivery_orders_count'], 'pickup' => $current['pickup_orders_count']],
            'comparison' => ['period' => $previousFilter->period(), 'current' => $current, 'previous' => $previous, 'changes' => $changes],
            'definitions' => ['completed_sales' => 'Completed order totals, grouped by placement time.',
                'collected_revenue' => 'Successful sale/capture ledger amounts, grouped by processing time.',
                'rates' => 'Completed or cancelled orders divided by all matching orders in the placement cohort.',
                'new_customers' => 'Registrations in period; with a branch/filter, restricted to matching order customers.']];
    }
}
