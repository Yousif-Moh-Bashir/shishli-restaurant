<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CatalogReportService
{
    public function __construct(private ReportQuery $queries) {}

    public function products(ReportFilterData $filter): Builder
    {
        $query = $this->queries->completed($filter)->join('order_items AS items', 'items.order_id', '=', 'orders.id')
            ->select('items.product_uuid')
            ->selectRaw('SUM(items.quantity) AS quantity_sold, COUNT(DISTINCT orders.id) AS orders_count')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.line_total').'), 0) AS gross_item_sales_minor')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.discount_total').'), 0) AS discount_total_minor')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.line_total_after_discount').'), 0) AS net_item_sales_minor')
            ->groupBy('items.product_uuid');
        $current = DB::table('products')->select('name')->whereColumn('uuid', 'product_totals.product_uuid')->whereNull('deleted_at')->limit(1);
        $snapshot = DB::table('order_items AS latest_item')->select('latest_item.product_name')
            ->joinSub($this->queries->completed($filter)->select('orders.id', 'orders.placed_at'), 'eligible', 'eligible.id', '=', 'latest_item.order_id')
            ->whereColumn('latest_item.product_uuid', 'product_totals.product_uuid')
            ->orderByDesc('eligible.placed_at')->orderByDesc('latest_item.id')->limit(1);
        $result = DB::query()->fromSub($query, 'product_totals')->select('product_totals.*')
            ->selectRaw('COALESCE(('.$current->toSql().'), ('.$snapshot->toSql().')) AS product_name', [...$current->getBindings(), ...$snapshot->getBindings()]);
        $sort = ['quantity' => 'quantity_sold', 'net_sales' => 'net_item_sales_minor', 'orders_count' => 'orders_count'][$filter->sortBy];

        return $result->orderByDesc($sort)->orderBy('product_uuid');
    }

    /** Legacy items are explicitly unclassified; current catalog assignments are never backfilled as history. */
    public function categories(ReportFilterData $filter): Builder
    {
        return $this->queries->completed($filter)->join('order_items AS items', 'items.order_id', '=', 'orders.id')
            ->select('items.category_uuid', 'items.category_name')
            ->selectRaw('SUM(items.quantity) AS quantity_sold, COUNT(DISTINCT orders.id) AS orders_count')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.line_total').'), 0) AS gross_sales_minor')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.discount_total').'), 0) AS discount_total_minor')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('items.line_total_after_discount').'), 0) AS net_sales_minor')
            ->groupBy('items.category_uuid', 'items.category_name')->orderByDesc('net_sales_minor')->orderBy('items.category_uuid')->orderBy('items.category_name');
    }

    public function promotions(ReportFilterData $filter, bool $coupons = false): Builder
    {
        $query = $this->queries->completed($filter)->join('order_promotions AS snapshots', 'snapshots.order_id', '=', 'orders.id')
            ->selectRaw('COUNT(*) AS usage_count, COUNT(DISTINCT orders.id) AS orders_count')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('snapshots.discount_amount').'), 0) AS discount_total_minor');
        if ($coupons) {
            return $query->whereNotNull('snapshots.coupon_code')->addSelect('snapshots.coupon_code AS code')
                ->groupBy('snapshots.coupon_code')->orderByDesc('usage_count')->orderBy('code');
        }

        // Whole-order attributed sales are non-additive across stacked promotions.
        return $query->addSelect('snapshots.promotion_uuid')->selectRaw('MAX(snapshots.promotion_name) AS promotion_name')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('orders.subtotal').'), 0) AS sales_before_discount_minor')
            ->selectRaw('COALESCE(SUM('.ReportMath::cents('orders.subtotal').' - '.ReportMath::cents('orders.discount_total').'), 0) AS sales_after_discount_minor')
            ->groupBy('snapshots.promotion_uuid')->orderByDesc('usage_count')->orderBy('snapshots.promotion_uuid');
    }
}
