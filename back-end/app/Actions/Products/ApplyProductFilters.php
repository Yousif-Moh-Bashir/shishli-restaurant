<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ApplyProductFilters
{
    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function handle(Builder $query, array $filters): Builder
    {
        if (isset($filters['category'])) {
            $query->whereHas('category', fn (Builder $category): Builder => $category
                ->where(fn (Builder $identity): Builder => $identity
                    ->where('uuid', $filters['category'])->orWhere('slug', $filters['category'])));
        }

        if (isset($filters['search'])) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(fn (Builder $search): Builder => $search
                ->where('name', 'like', $pattern)->orWhere('sku', 'like', $pattern)
                ->orWhere('short_description', 'like', $pattern)->orWhere('description', 'like', $pattern));
        }

        foreach (['featured' => 'is_featured', 'available' => 'is_available', 'is_active' => 'is_active', 'is_available' => 'is_available', 'is_featured' => 'is_featured'] as $filter => $column) {
            if (array_key_exists($filter, $filters)) {
                if ((bool) $filters[$filter]) {
                    match ($column) {
                        'is_active' => $query->active(),
                        'is_available' => $query->available(),
                        'is_featured' => $query->featured(),
                    };
                } else {
                    $query->where($column, false);
                }
            }
        }

        match ($filters['sort'] ?? 'default') {
            'price_asc' => $query->orderBy('base_price'),
            'price_desc' => $query->orderByDesc('base_price'),
            'name' => $query->orderBy('name'),
            'latest' => $query->orderByDesc('created_at'),
            default => $query->orderBy('sort_order')->orderBy('name'),
        };

        return $query->orderBy('id');
    }
}
