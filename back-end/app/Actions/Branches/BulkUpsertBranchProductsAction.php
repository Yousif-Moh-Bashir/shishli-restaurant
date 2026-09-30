<?php

namespace App\Actions\Branches;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkUpsertBranchProductsAction
{
    /** @param list<array<string, mixed>> $items */
    public function handle(Branch $branch, array $items): Collection
    {
        return DB::transaction(function () use ($branch, $items): Collection {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $products = Product::whereIn('uuid', array_column($items, 'product_uuid'))->orderBy('id')->lockForUpdate()->get()->keyBy('uuid');
            $existing = BranchProduct::where('branch_id', $branch->id)->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');
            $rows = [];
            foreach ($items as $index => $item) {
                $product = $products->get($item['product_uuid']);
                if ($product === null) {
                    throw ValidationException::withMessages(["products.$index.product_uuid" => 'المنتج المحدد غير موجود.']);
                }
                $current = $existing->get($product->id);
                $rows[] = [
                    'branch_id' => $branch->id, 'product_id' => $product->id,
                    'price_override' => array_key_exists('price_override', $item) ? $item['price_override'] : $current?->price_override,
                    'is_available' => $item['is_available'] ?? $current?->is_available ?? true,
                ];
            }
            BranchProduct::upsert($rows, ['branch_id', 'product_id'], ['price_override', 'is_available', 'updated_at']);

            return BranchProduct::where('branch_id', $branch->id)->whereIn('product_id', $products->pluck('id'))
                ->with(['product.category', 'product.primaryImage'])->orderBy('id')->get()
                ->each(fn (BranchProduct $assignment) => $assignment->setRelation('branch', $branch));
        }, 3);
    }
}
