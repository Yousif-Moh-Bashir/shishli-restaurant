<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

class SetPrimaryProductImageAction
{
    public function handle(Product $product, ProductImage $image): ProductImage
    {
        return DB::transaction(function () use ($product, $image): ProductImage {
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $image = $product->images()->whereKey($image->getKey())->firstOrFail();
            $product->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);

            return $image->refresh();
        }, 3);
    }
}
