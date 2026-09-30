<?php

namespace App\Actions\Products;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListProductsAction
{
    public function __construct(private ApplyProductFilters $applyFilters) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        if (isset($filters['branch'])) {
            Branch::where('is_active', true)
                ->where(fn (Builder $query): Builder => $query->where('uuid', $filters['branch'])->orWhere('slug', $filters['branch']))
                ->firstOrFail();
        }

        $query = Product::visibleInMenu()->with(['category', 'primaryImage']);

        return $this->applyFilters->handle($query, $filters)
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($filters);
    }
}
