<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;

class ReportService
{
    public const PERMISSIONS = [
        'orders' => 'reports.orders.view', 'sales/trend' => 'reports.sales.view', 'branches' => 'reports.branches.view',
        'products' => 'reports.products.view', 'categories' => 'reports.products.view', 'payments' => 'reports.payments.view',
        'promotions' => 'reports.promotions.view', 'coupons' => 'reports.promotions.view', 'customers' => 'reports.customers.view',
        'delivery' => 'reports.delivery.view', 'order-statuses' => 'reports.orders.view', 'cancellations' => 'reports.orders.view',
    ];

    public function __construct(private ReportQuery $queries, private SalesReportService $sales,
        private CatalogReportService $catalog, private PaymentReportService $payments,
        private AudienceReportService $audience, private OperationsReportService $operations) {}

    public function rows(string $report, ReportFilterData $filter): Builder
    {
        return match ($report) {
            'orders' => $this->queries->orders($filter)->select('orders.uuid AS order_uuid', 'orders.order_number', 'orders.type', 'orders.status',
                'orders.payment_method', 'orders.placed_at')->selectRaw(ReportMath::cents('orders.total').' AS total_minor')->orderBy('orders.id'),
            'branches' => $this->sales->branches($filter),
            'products' => $this->catalog->products($filter), 'categories' => $this->catalog->categories($filter),
            'payments' => $this->payments->breakdown($filter),
            'promotions' => $this->catalog->promotions($filter), 'coupons' => $this->catalog->promotions($filter, true),
            'customers' => $this->audience->customers($filter), 'delivery' => $this->operations->zones($filter),
            'order-statuses' => $this->operations->statuses($filter), 'cancellations' => $this->operations->cancellations($filter),
        };
    }

    public function get(string $report, ReportFilterData $filter): array
    {
        if ($report === 'sales/trend') {
            return ['period' => $filter->period(), 'group_by' => $filter->groupBy, 'data' => $this->sales->trend($filter)];
        }
        $summary = match ($report) {
            'orders' => $this->queries->summary($filter),
            'payments' => $this->payments->summary($filter),
            'customers' => $this->audience->summary($filter),
            'delivery' => $this->operations->deliverySummary($filter),
            'order-statuses' => $this->operations->timings($filter),
            'cancellations' => ReportMath::row($this->queries->orders($filter)->where('orders.status', 'cancelled')
                ->selectRaw('COUNT(*) AS cancelled_orders_count, COALESCE(SUM('.ReportMath::cents('orders.total').'), 0) AS cancelled_sales_value_minor')->first()),
            default => [],
        };

        return ['period' => $filter->period(), 'summary' => $summary,
            ...$this->queries->page($this->rows($report, $filter), $filter),
            'definitions' => match ($report) {
                'products' => ['names' => 'Current active catalog name, otherwise latest matching snapshot; amounts are historical snapshots.'],
                'categories' => ['history' => 'Recorded category snapshots; legacy null categories remain unclassified; renamed categories form separate snapshot groups.'],
                'promotions' => ['sales' => 'Completed-order subtotal before/after all discounts. Non-additive attribution across stacked promotions; no ROI.'],
                'payments' => ['period' => 'Payments count by creation, ledger counts/amounts by processing (pending attempts use creation); refunds unsupported.'],
                default => ['cohort' => 'Orders placed in selected business dates; inactive/deleted branches remain included.'],
            }];
    }
}
