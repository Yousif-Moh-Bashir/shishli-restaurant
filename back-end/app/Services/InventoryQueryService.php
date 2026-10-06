<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class InventoryQueryService
{
    public function inventories(array $filters): Builder
    {
        $query = BranchInventory::query()->with(['branch', 'product.category']);
        foreach (['branch_uuid' => 'branch', 'product_uuid' => 'product'] as $key => $relation) {
            if (isset($filters[$key])) {
                $query->whereHas($relation, fn (Builder $related) => $related->where('uuid', $filters[$key]));
            }
        }
        if (isset($filters['category_uuid'])) {
            $query->whereHas('product.category', fn (Builder $category) => $category->where('uuid', $filters['category_uuid']));
        }
        if (isset($filters['search'])) {
            $query->whereHas('product', fn (Builder $product) => $product->where(fn (Builder $search) => $search
                ->where('name', 'like', '%'.$filters['search'].'%')->orWhere('sku', 'like', '%'.$filters['search'].'%')));
        }
        if (isset($filters['tracking']) || isset($filters['is_tracking'])) {
            $query->where('is_tracking', (bool) ($filters['tracking'] ?? $filters['is_tracking']));
        }
        if (! empty($filters['low_stock'])) {
            $query->where('is_tracking', true)->whereNotNull('low_stock_threshold')
                ->whereHas('product', fn (Builder $product) => $product->where('tracks_inventory', true))
                ->whereRaw('(on_hand - reserved) <= low_stock_threshold');
        }
        if (! empty($filters['out_of_stock'])) {
            $query->where('is_tracking', true)
                ->whereHas('product', fn (Builder $product) => $product->where('tracks_inventory', true))
                ->whereRaw('(on_hand - reserved) <= 0');
        }

        return $query;
    }

    public function sorted(array $filters): Builder
    {
        $query = $this->inventories($filters);
        $direction = $filters['direction'] ?? 'asc';
        switch ($filters['sort'] ?? 'updated_at') {
            case 'available': $query->orderByRaw('(on_hand - reserved) '.$direction);
                break;
            case 'product_name':
                $query->orderBy(Product::withTrashed()->select('name')->whereColumn('products.id', 'branch_inventories.product_id'), $direction);
                break;
            default: $query->orderBy($filters['sort'] ?? 'updated_at', $direction);
        }

        return $query->orderBy('id');
    }

    public function movements(array $filters, ?BranchInventory $inventory = null): Builder
    {
        $query = StockMovement::query()->with(['inventory.branch', 'inventory.product', 'createdBy']);
        if ($inventory !== null) {
            $query->where('branch_inventory_id', $inventory->id);
        }
        foreach (['branch_uuid' => 'inventory.branch', 'product_uuid' => 'inventory.product'] as $key => $relation) {
            if (isset($filters[$key])) {
                $query->whereHas($relation, fn (Builder $related) => $related->where('uuid', $filters[$key]));
            }
        }
        foreach (['type', 'reference_type', 'reference_uuid'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $tz = app(RestaurantSettingsService::class)->getString('restaurant.timezone');
        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['date_from'], $tz)->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['date_to'], $tz)->startOfDay()->addDay()->utc());
        }

        return $query->orderByDesc('id');
    }
}
