<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReorderProductImagesAction
{
    /**
     * @param  list<array{id: string, sort_order: int}>  $order
     * @return Collection<int, ProductImage>
     */
    public function handle(Product $product, array $order): Collection
    {
        return DB::transaction(function () use ($product, $order): Collection {
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $images = $product->images()->get()->keyBy(fn (ProductImage $image): string => Str::lower($image->uuid));
            $rows = [];

            foreach ($order as $item) {
                $image = $images->get(Str::lower($item['id']));
                abort_unless($image !== null, 404);
                $rows[] = array_replace($image->getAttributes(), [
                    'sort_order' => $item['sort_order'],
                    'updated_at' => now(),
                ]);
            }

            ProductImage::upsert($rows, ['id'], ['sort_order', 'updated_at']);

            return $product->images()->get();
        }, 3);
    }
}
