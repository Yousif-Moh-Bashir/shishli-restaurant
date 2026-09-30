<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DetachBranchProductAction
{
    public function handle(Branch $branch, Product $product): void
    {
        DB::transaction(function () use ($branch, $product): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail()->delete();
        }, 3);
    }
}
