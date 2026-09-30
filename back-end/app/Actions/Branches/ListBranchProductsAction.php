<?php

namespace App\Actions\Branches;

use App\Actions\Products\ApplyProductFilters;
use App\Models\Branch;
use App\Models\BranchProduct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ListBranchProductsAction
{
    public function __construct(private ApplyProductFilters $filters) {}

    public function handle(Branch $branch, array $data): LengthAwarePaginator
    {
        $query = BranchProduct::where('branch_id', $branch->id)
            ->whereHas('product', fn (Builder $query): Builder => $this->filters->handle($query, Arr::only($data, ['search', 'category'])))
            ->with(['product.category', 'product.primaryImage']);
        if (isset($data['is_available'])) {
            $query->where('is_available', (bool) $data['is_available']);
        }
        $paginator = $query->orderBy('id')->paginate($data['per_page'] ?? 20)->withQueryString();
        $paginator->getCollection()->each(fn (BranchProduct $assignment) => $assignment->setRelation('branch', $branch));

        return $paginator;
    }
}
