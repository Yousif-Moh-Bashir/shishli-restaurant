<?php

namespace App\Services\Reports;

use App\Services\InventoryQueryService;

class InventoryReportService
{
    public function __construct(private InventoryQueryService $queries) {}

    public function get(array $filters = []): array
    {
        $query = $this->queries->inventories($filters)->where('is_tracking', true)
            ->whereHas('product', fn ($product) => $product->where('tracks_inventory', true));
        $row = $query->toBase()->selectRaw('COUNT(DISTINCT product_id) AS products, COUNT(*) AS inventories,
            COALESCE(SUM(CASE WHEN on_hand - reserved <= 0 THEN 1 ELSE 0 END), 0) AS unavailable,
            COALESCE(SUM(CASE WHEN low_stock_threshold IS NOT NULL AND on_hand - reserved <= low_stock_threshold THEN 1 ELSE 0 END), 0) AS low')->first();

        return ['as_of' => now()->toISOString(), 'tracked_products_count' => (int) $row->products,
            'tracked_inventories_count' => (int) $row->inventories, 'out_of_stock_count' => (int) $row->unavailable,
            'low_stock_count' => (int) $row->low];
    }
}
