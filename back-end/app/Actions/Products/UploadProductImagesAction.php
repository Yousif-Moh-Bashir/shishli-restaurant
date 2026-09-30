<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UploadProductImagesAction
{
    public function __construct(private MediaService $media) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, ProductImage>
     */
    public function handle(Product $product, array $files, ?string $altText = null, bool $isPrimary = false): Collection
    {
        $storedPaths = [];
        $disk = config('filesystems.media_disk', 'public');

        try {
            return DB::transaction(function () use ($product, $files, $altText, $isPrimary, $disk, &$storedPaths): Collection {
                $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();

                if ($product->images()->count() + count($files) > 20) {
                    throw ValidationException::withMessages(['images' => 'لا يمكن أن يتجاوز عدد صور المنتج 20 صورة.']);
                }

                $nextOrder = ($product->images()->max('sort_order') ?? -1) + 1;

                if ($nextOrder + count($files) - 1 > 2147483647) {
                    throw ValidationException::withMessages(['images' => 'يرجى إعادة ترتيب الصور قبل إضافة صور جديدة.']);
                }

                $needsPrimary = $isPrimary || ! $product->primaryImage()->exists();

                if ($isPrimary) {
                    $product->images()->update(['is_primary' => false]);
                }

                $images = new Collection;

                foreach ($files as $file) {
                    $path = $this->media->store($file, 'products/'.$product->uuid, $disk);
                    $storedPaths[] = $path;
                    $images->push($product->images()->create([
                        'disk' => $disk,
                        'path' => $path,
                        'alt_text' => $altText,
                        'sort_order' => $nextOrder++,
                        'is_primary' => $needsPrimary,
                    ])->refresh());
                    $needsPrimary = false;
                }

                return $images;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                try {
                    $this->media->delete($path, $disk);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}
