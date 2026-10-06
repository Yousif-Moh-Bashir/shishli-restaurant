<?php

namespace App\Services\Reports;

use App\Services\Customers\CustomerLookupService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AudienceReportService
{
    public function __construct(private ReportQuery $queries, private CustomerLookupService $customers) {}

    public function customerQuery(): Builder
    {
        return $this->customers->customers()->toBase();
    }

    /** New registrations in a branch cohort must have a matching order; no home-branch association exists. */
    public function newCustomers(ReportFilterData $filter): int
    {
        $query = $this->customerQuery()->where('users.created_at', '>=', $filter->from)->where('users.created_at', '<', $filter->until);
        if ($filter->branchUuid !== null || $filter->orderType !== null || $filter->orderStatus !== null || $filter->paymentMethod !== null) {
            $query->whereIn('users.id', $this->queries->orders($filter)->select('orders.user_id')->whereNotNull('orders.user_id'));
        }

        return $query->count();
    }

    public function customers(ReportFilterData $filter): Builder
    {
        $totals = $this->queries->summaryQuery($filter)->addSelect('orders.user_id')->whereNotNull('orders.user_id')->groupBy('orders.user_id');

        return $this->customerQuery()->joinSub($totals, 'totals', 'totals.user_id', '=', 'users.id')
            ->select('users.uuid AS customer_uuid', 'users.name', 'totals.orders_count', 'totals.completed_orders_count', 'totals.last_order_at')
            ->selectRaw('totals.completed_sales_minor AS total_spent_minor')
            ->orderByDesc('total_spent_minor')->orderBy('users.uuid');
    }

    public function summary(ReportFilterData $filter): array
    {
        $cohort = $this->customerQuery();
        if ($filter->branchUuid !== null || $filter->orderType !== null || $filter->orderStatus !== null || $filter->paymentMethod !== null) {
            $cohort->whereIn('users.id', $this->queries->orders($filter)->select('orders.user_id')->whereNotNull('orders.user_id'));
        }
        $ids = $this->customerQuery()->select('users.id');
        $counts = $this->queries->orders($filter)->whereIn('orders.user_id', $ids)->select('orders.user_id')
            ->selectRaw('COUNT(*) AS order_count')->groupBy('orders.user_id');
        $aggregate = DB::query()->fromSub($counts, 'customer_orders')
            ->selectRaw('COUNT(*) AS customers_with_orders, COUNT(CASE WHEN order_count > 1 THEN 1 END) AS returning_customers_count')->first();

        return ['registered_customers_count' => $cohort->count(),
            'new_registered_customers' => $this->newCustomers($filter),
            'customers_with_orders' => (int) $aggregate->customers_with_orders,
            'returning_customers_count' => (int) $aggregate->returning_customers_count,
            'guest_orders_count' => $this->queries->orders($filter)->whereNull('orders.user_id')->count(),
            'returning_customer_definition' => 'Registered customer with more than one matching order in the selected period.'];
    }
}
