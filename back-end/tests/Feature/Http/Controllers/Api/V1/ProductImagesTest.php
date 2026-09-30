<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_product_details_include_ordered_images_with_public_urls_and_primary_flag(): void
    {
        config(['filesystems.disks.public.url' => 'https://images.example.test/storage']);
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->create(['path' => 'products/image-3.webp', 'sort_order' => 20]);
        ProductImage::factory()->for($product)->create([
            'path' => 'products/main.webp', 'alt_text' => 'شيش طاووق', 'sort_order' => 0, 'is_primary' => true,
        ]);
        ProductImage::factory()->for($product)->create(['path' => 'products/image-2.webp', 'sort_order' => 10]);
        ProductImage::factory()->create(['path' => 'products/another-product.webp']);

        $response = $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonCount(3, 'data.images');

        $this->assertSame([
            ['id' => $product->images()->where('path', 'products/main.webp')->sole()->uuid, 'url' => 'https://images.example.test/storage/products/main.webp', 'alt_text' => 'شيش طاووق', 'sort_order' => 0, 'is_primary' => true],
            ['id' => $product->images()->where('path', 'products/image-2.webp')->sole()->uuid, 'url' => 'https://images.example.test/storage/products/image-2.webp', 'alt_text' => null, 'sort_order' => 10, 'is_primary' => false],
            ['id' => $product->images()->where('path', 'products/image-3.webp')->sole()->uuid, 'url' => 'https://images.example.test/storage/products/image-3.webp', 'alt_text' => null, 'sort_order' => 20, 'is_primary' => false],
        ], $response->json('data.images'));
    }

    public function test_products_without_images_return_empty_arrays(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.primary_image', null)->assertJsonMissingPath('data.0.images');
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.images', []);
    }

    public function test_product_list_loads_images_in_one_query_for_multiple_products(): void
    {
        $products = Product::factory()->count(3)->create();
        foreach ($products as $product) {
            ProductImage::factory()->for($product)->create(['is_primary' => true]);
        }
        DB::enableQueryLog();

        try {
            $response = $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(3, 'data');
            $imageQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'product_images'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(1, $imageQueries);
        foreach ($response->json('data') as $product) {
            $this->assertNotNull($product['primary_image']);
            $this->assertArrayNotHasKey('images', $product);
        }
    }

    public function test_images_have_stable_order_for_equal_sort_values_and_belong_to_product(): void
    {
        $product = Product::factory()->create();
        $first = ProductImage::factory()->for($product)->create();
        $second = ProductImage::factory()->for($product)->create();

        $this->assertSame([$first->id, $second->id], $product->images->modelKeys());
        $this->assertTrue($first->product->is($product));
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertNull($first->fresh()->alt_text);
        $this->assertSame(0, $first->fresh()->sort_order);
    }

    public function test_force_deleting_product_removes_only_its_image_records(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create(['is_primary' => true]);
        $otherImage = ProductImage::factory()->create();

        $product->forceDelete();

        $this->assertModelMissing($image);
        $this->assertModelExists($otherImage);
    }

    public function test_image_cannot_reference_missing_product(): void
    {
        $this->expectException(QueryException::class);

        ProductImage::factory()->create(['product_id' => 99999]);
    }
}
