<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminProductsAction
{
    public function __construct(private ApplyProductFilters $applyFilters) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        return $this->applyFilters->handle(Product::with(['category', 'primaryImage']), $filters)
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($filters);
    }
}
