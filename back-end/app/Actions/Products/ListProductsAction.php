<?php

namespace App\Actions\Products;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListProductsAction
{
    /**
     * @param  array{category?: ?string, branch?: ?string, search?: ?string, featured?: bool|int|string, page?: int|string, per_page?: int|string}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        if (isset($filters['branch'])) {
            Branch::where('is_active', true)
                ->where(fn (Builder $query): Builder => $query->where('uuid', $filters['branch'])->orWhere('slug', $filters['branch']))
                ->firstOrFail();
        }

        $query = Product::visibleInMenu()->with(['category', 'images']);

        if (isset($filters['category'])) {
            $query->whereHas('category', fn (Builder $category): Builder => $category->where('slug', $filters['category']));
        }

        if (isset($filters['search'])) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(fn (Builder $search): Builder => $search->where('name', 'like', $pattern)
                ->orWhere('short_description', 'like', $pattern)->orWhere('description', 'like', $pattern));
        }

        if (array_key_exists('featured', $filters)) {
            $query->where('is_featured', (bool) $filters['featured']);
        }

        return $query->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($filters);
    }
}
