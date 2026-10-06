<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Services\Admin\AdminProductFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListAdminProductsAction
{
    public function __construct(private ApplyProductFilters $applyFilters) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $filters = AdminProductFilters::fromArray($filters)->values;
        $query = $this->applyFilters->handle(Product::with(['category', 'primaryImage']), $filters);
        if (isset($filters['branch_uuid'])) {
            $query->whereHas('branches', fn (Builder $branch) => $branch->where('branches.uuid', $filters['branch_uuid']));
        }
        if (array_key_exists('tracks_inventory', $filters)) {
            $query->where('tracks_inventory', (bool) $filters['tracks_inventory']);
        }
        if (in_array($filters['sort'] ?? null, ['base_price', 'created_at', 'updated_at', 'sku', 'sort_order', 'name'], true)) {
            $query->reorder()->orderBy($filters['sort'], $filters['direction'] ?? 'asc')->orderBy('products.id');
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($filters);
    }
}
