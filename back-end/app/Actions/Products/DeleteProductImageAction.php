<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\MediaService;
use Illuminate\Support\Facades\DB;

class DeleteProductImageAction
{
    public function __construct(private MediaService $media) {}

    public function handle(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image): void {
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $image = $product->images()->whereKey($image->getKey())->firstOrFail();
            $disk = $image->disk;
            $path = $image->path;
            $wasPrimary = $image->is_primary;
            $image->delete();

            if ($wasPrimary) {
                $product->images()->first()?->update(['is_primary' => true]);
            }

            $this->media->delete($path, $disk);
        });
    }
}
