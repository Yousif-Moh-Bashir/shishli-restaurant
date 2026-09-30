<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateBranchProductAction
{
    public function handle(Branch $branch, Product $product, array $data): BranchProduct
    {
        return DB::transaction(function () use ($branch, $product, $data): BranchProduct {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $assignment = BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
            $assignment->update(Arr::only($data, ['price_override', 'is_available']));

            return $assignment->refresh()->setRelation('branch', $branch)
                ->setRelation('product', $product->load(['category', 'primaryImage']));
        }, 3);
    }
}
