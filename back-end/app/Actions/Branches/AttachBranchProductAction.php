<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachBranchProductAction
{
    public function handle(Branch $branch, array $data): BranchProduct
    {
        return DB::transaction(function () use ($branch, $data): BranchProduct {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $product = Product::where('uuid', $data['product_uuid'])->lockForUpdate()->first();
            if ($product === null) {
                throw ValidationException::withMessages(['product_uuid' => 'المنتج المحدد غير موجود.']);
            }
            if (BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product_uuid' => 'المنتج مضاف بالفعل إلى هذا الفرع.']);
            }
            $assignment = BranchProduct::create(Arr::only($data, ['price_override', 'is_available']) + [
                'branch_id' => $branch->id, 'product_id' => $product->id,
            ])->refresh();

            return $assignment->setRelation('branch', $branch)->setRelation('product', $product->load(['category', 'primaryImage']));
        }, 3);
    }
}
