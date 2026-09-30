<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;

class ProductAvailabilityService
{
    public function isAvailable(Product $product, Branch $branch, ?BranchProduct $assignment): bool
    {
        return ! $branch->trashed() && $branch->is_active
            && ! $product->trashed() && $product->is_active && $product->is_available
            && $assignment !== null && $assignment->exists
            && $assignment->branch_id === $branch->id && $assignment->product_id === $product->id
            && $assignment->is_available;
    }
}
