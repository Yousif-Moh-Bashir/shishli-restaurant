<?php

namespace App\Services;

use App\Actions\Products\ApplyProductFilters;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MenuService
{
    public function __construct(private ApplyProductFilters $filters) {}

    public function branch(string $uuid): Branch
    {
        $branch = Branch::where('uuid', $uuid)->where('is_active', true)->first();
        if ($branch === null) {
            throw ValidationException::withMessages(['branch' => 'الفرع المحدد غير متاح.']);
        }

        return $branch;
    }

    /** @return Collection<int, Category> */
    public function categories(Branch $branch, array $filters): Collection
    {
        $query = $this->query($branch)
            ->whereHas('product', fn (Builder $query): Builder => $this->filters->handle(
                $query, array_filter(Arr::only($filters, ['category', 'search', 'featured']), fn (mixed $value): bool => $value !== null)
            ));
        if (isset($filters['available'])) {
            if ((bool) $filters['available']) {
                $query->where('is_available', true)->whereHas('product', fn (Builder $product): Builder => $product->available());
            } else {
                $query->where(fn (Builder $query): Builder => $query->where('is_available', false)
                    ->orWhereHas('product', fn (Builder $product): Builder => $product->where('is_available', false)));
            }
        }
        $assignments = $query->get()->each(fn (BranchProduct $assignment) => $assignment->setRelation('branch', $branch));

        return $assignments->groupBy(fn (BranchProduct $assignment): int => $assignment->product->category_id)
            ->map(function (Collection $items): Category {
                $category = clone $items->first()->product->category;
                $category->setRelation('menuProducts', $items->sortBy([
                    ['product.sort_order', 'asc'], ['product.name', 'asc'], ['product.id', 'asc'],
                ])->values());

                return $category;
            })->sortBy([['sort_order', 'asc'], ['name', 'asc'], ['id', 'asc']])->values();
    }

    public function product(Branch $branch, Product $product): BranchProduct
    {
        return $this->query($branch)->where('product_id', $product->id)
            ->with([
                'product.images',
                'product.optionGroups' => fn (BelongsToMany $groups): BelongsToMany => $groups->where('option_groups.is_active', true),
                'product.optionGroups.values' => fn (HasMany $values): HasMany => $values->where('is_active', true),
            ])->firstOrFail()->setRelation('branch', $branch);
    }

    private function query(Branch $branch): Builder
    {
        return BranchProduct::where('branch_id', $branch->id)
            ->whereHas('product', fn (Builder $query): Builder => $query->visibleInMenu())
            ->with(['product.category', 'product.primaryImage']);
    }
}
