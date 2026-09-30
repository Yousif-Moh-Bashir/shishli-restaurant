<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\MediaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_image_routes_require_products_update(string $method, string $suffix): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();
        $url = '/api/v1/admin/products/'.$product->uuid.'/images'.str_replace('{image}', $image->uuid, $suffix);
        $this->json($method, $url)->assertUnauthorized();
        $this->authorizeImages('products.view');
        $this->json($method, $url)->assertForbidden();
        $this->assertModelExists($image);
    }

    public static function endpoints(): array
    {
        return [
            'upload' => ['POST', ''], 'delete' => ['DELETE', '/{image}'],
            'primary' => ['PATCH', '/{image}/primary'], 'reorder' => ['PATCH', '/reorder'],
        ];
    }

    public function test_upload_stores_multiple_files_and_metadata_without_accepting_internal_fields(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $uuid = (string) Str::uuid();
        $response = $this->postJson($this->base($product), [
            'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.png')],
            'alt_text' => 'شيش طاووق', 'disk' => 'private', 'path' => '../attack.php',
            'product_id' => 9000, 'uuid' => $uuid,
        ])->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_primary', true)
            ->assertJsonPath('data.1.is_primary', false)->assertJsonPath('data.1.sort_order', 1);
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
            $this->assertStringStartsWith('products/'.$product->uuid.'/', $image->path);
            $this->assertTrue(Str::isUuid($image->uuid));
            $this->assertNotSame($uuid, $image->uuid);
            $this->assertSame('public', $image->disk);
            $this->assertSame('شيش طاووق', $image->alt_text);
        }
        $this->assertSame($product->images[0]->uuid, $response->json('data.0.id'));
        foreach (['disk', 'path', 'product_id'] as $field) {
            $response->assertJsonMissingPath('data.0.'.$field);
        }
    }

    public function test_additional_uploads_append_order_and_only_replace_primary_when_requested(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->for($product)->create(['is_primary' => true, 'sort_order' => 4]);
        $this->postJson($this->base($product), ['images' => [UploadedFile::fake()->image('new.jpg')]])
            ->assertCreated()->assertJsonPath('data.0.sort_order', 5)->assertJsonPath('data.0.is_primary', false);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->postJson($this->base($product), [
            'images' => [UploadedFile::fake()->image('new.jpg'), UploadedFile::fake()->image('second.jpg')], 'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.0.is_primary', true)->assertJsonPath('data.1.is_primary', false);
        $this->assertFalse($primary->fresh()->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    #[DataProvider('badFiles')]
    public function test_non_images_and_disallowed_formats_are_rejected(string $name, string $mime): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $this->postJson($this->base($product), ['images' => [UploadedFile::fake()->create($name, 10, $mime)]])
            ->assertUnprocessable()->assertJsonValidationErrors('images.0');
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function badFiles(): array
    {
        return [
            'php' => ['file.php', 'application/x-httpd-php'], 'exe' => ['file.exe', 'application/octet-stream'],
            'svg' => ['file.svg', 'image/svg+xml'], 'pdf' => ['file.pdf', 'application/pdf'],
            'text' => ['file.txt', 'text/plain'], 'disguised text' => ['image.jpg', 'text/plain'],
            'gif' => ['image.gif', 'image/gif'],
        ];
    }

    public function test_upload_validates_required_array_size_alt_and_primary_flag(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $this->postJson($this->base($product), [])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->postJson($this->base($product), ['images' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->postJson($this->base($product), ['images' => []])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->postJson($this->base($product), [
            'images' => [UploadedFile::fake()->image('big.jpg')->size(5121)],
            'alt_text' => str_repeat('a', 256), 'is_primary' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0', 'alt_text', 'is_primary']);
        $this->postJson($this->base($product), [
            'images' => array_map(fn (): UploadedFile => UploadedFile::fake()->image('photo.jpg'), range(1, 11)),
        ])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_total_limit_rejects_entire_batch_before_storing_files(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        ProductImage::factory()->count(18)->for($product)->create();
        $this->postJson($this->base($product), [
            'images' => array_map(fn (): UploadedFile => UploadedFile::fake()->image('photo.jpg'), range(1, 3)),
        ])->assertUnprocessable()->assertJsonPath('errors.images.0', 'لا يمكن أن يتجاوز عدد صور المنتج 20 صورة.');
        $this->assertSame(18, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->postJson($this->base($product), [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertCreated();
        $this->assertSame(20, $product->images()->count());
    }

    public function test_primary_switch_is_exclusive_and_cross_product_access_returns_404(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $old = ProductImage::factory()->for($product)->create(['is_primary' => true]);
        $new = ProductImage::factory()->for($product)->create();
        $foreign = ProductImage::factory()->create();
        $this->patchJson($this->base($product).'/'.$new->uuid.'/primary')->assertOk()->assertJsonPath('data.is_primary', true);
        $this->assertFalse($old->fresh()->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->patchJson($this->base($product).'/'.$foreign->uuid.'/primary')->assertNotFound();
        $this->deleteJson($this->base($product).'/'.$foreign->uuid)->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_deletion_removes_file_and_promotes_first_remaining_image(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $primary = $this->imageWithFile($product, ['is_primary' => true, 'sort_order' => 0]);
        $last = $this->imageWithFile($product, ['sort_order' => 8]);
        $first = $this->imageWithFile($product, ['sort_order' => 3]);
        $this->deleteJson($this->base($product).'/'.$primary->uuid)->assertOk();
        $this->assertModelMissing($primary);
        Storage::disk('public')->assertMissing($primary->path);
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($last->fresh()->is_primary);
        $this->deleteJson($this->base($product).'/'.$last->uuid)->assertOk();
        $this->assertTrue($first->fresh()->is_primary);
        $this->deleteJson($this->base($product).'/'.$first->uuid)->assertOk();
        $this->assertNull($product->primaryImage()->first());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_reorder_is_atomic_preserves_primary_and_rejects_foreign_images(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $first = ProductImage::factory()->for($product)->create(['is_primary' => true, 'sort_order' => 0]);
        $second = ProductImage::factory()->for($product)->create(['sort_order' => 1]);
        $foreign = ProductImage::factory()->create();
        $this->patchJson($this->base($product).'/reorder', ['images' => [
            ['id' => $first->uuid, 'sort_order' => 4], ['id' => $second->uuid, 'sort_order' => 2],
        ]])->assertOk()->assertJsonPath('data.0.id', $second->uuid);
        $this->assertSame(4, $first->fresh()->sort_order);
        $this->assertTrue($first->fresh()->is_primary);
        $this->patchJson($this->base($product).'/reorder', ['images' => [
            ['id' => $first->uuid, 'sort_order' => 0], ['id' => $foreign->uuid, 'sort_order' => 1],
        ]])->assertNotFound();
        $this->assertSame(4, $first->fresh()->sort_order);
    }

    public function test_reorder_rejects_duplicates_invalid_ids_and_invalid_orders(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();
        $this->patchJson($this->base($product).'/reorder', ['images' => [
            ['id' => $image->uuid, 'sort_order' => 1], ['id' => $image->uuid, 'sort_order' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['images.0.id', 'images.0.sort_order']);
        $this->patchJson($this->base($product).'/reorder', ['images' => [
            ['id' => 'invalid', 'sort_order' => -1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['images.0.id', 'images.0.sort_order']);
        $this->assertSame(0, $image->fresh()->sort_order);
    }

    public function test_failed_fourth_upload_rolls_back_records_and_cleans_all_previous_files(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $primary = $this->imageWithFile($product, ['is_primary' => true]);
        $realMedia = new MediaService;
        $calls = 0;
        $this->partialMock(MediaService::class)->shouldReceive('store')->times(4)
            ->andReturnUsing(function (UploadedFile $file, string $directory, string $disk) use ($realMedia, &$calls): string {
                if (++$calls === 4) {
                    throw new RuntimeException('Simulated filesystem failure.');
                }

                return $realMedia->store($file, $directory, $disk);
            });
        $this->postJson($this->base($product), [
            'images' => array_map(fn (): UploadedFile => UploadedFile::fake()->image('photo.jpg'), range(1, 4)),
            'is_primary' => true,
        ])->assertStatus(500);
        $this->assertDatabaseCount('product_images', 1);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame([$primary->path], Storage::disk('public')->allFiles());
    }

    public function test_database_failure_after_upload_also_cleans_files(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $existing = ProductImage::factory()->for($product)->create();
        $dispatcher = ProductImage::getEventDispatcher();
        ProductImage::setEventDispatcher(clone $dispatcher);
        ProductImage::creating(function (ProductImage $image) use ($existing): void {
            $image->uuid = $existing->uuid;
        });
        try {
            $this->postJson($this->base($product), ['images' => [UploadedFile::fake()->image('photo.jpg')]])->assertStatus(500);
            $this->assertDatabaseCount('product_images', 1);
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            ProductImage::setEventDispatcher($dispatcher);
        }
    }

    public function test_file_delete_failure_rolls_back_record_and_primary_changes(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $primary = $this->imageWithFile($product, ['is_primary' => true]);
        $other = $this->imageWithFile($product);
        $this->partialMock(MediaService::class)->shouldReceive('delete')->once()->andThrow(new RuntimeException('Storage unavailable.'));
        $this->deleteJson($this->base($product).'/'.$primary->uuid)->assertStatus(500);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($other->fresh()->is_primary);
        Storage::disk('public')->assertExists($primary->path);
    }

    public function test_soft_delete_preserves_files_and_force_delete_cleans_them_after_commit(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $image = $this->imageWithFile($product);
        $product->delete();
        $this->assertModelExists($image);
        Storage::disk('public')->assertExists($image->path);
        $product->restore();
        $product->forceDelete();
        $this->assertModelMissing($image);
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_rolled_back_force_delete_does_not_remove_files(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $image = $this->imageWithFile($product);
        try {
            DB::transaction(function () use ($product): void {
                $product->forceDelete();
                throw new RuntimeException('Rollback.');
            });
        } catch (RuntimeException) {
            $this->assertModelExists($product);
            $this->assertModelExists($image);
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_soft_deleted_product_cannot_receive_images(): void
    {
        Storage::fake('public');
        $this->authorizeImages();
        $product = Product::factory()->create();
        $product->delete();
        $this->postJson($this->base($product), ['images' => [UploadedFile::fake()->image('photo.jpg')]])->assertNotFound();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function authorizeImages(string $permission = 'products.update'): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo($permission);
        $this->withToken($user->createToken('images')->plainTextToken);
    }

    private function base(Product $product): string
    {
        return '/api/v1/admin/products/'.$product->uuid.'/images';
    }

    private function imageWithFile(Product $product, array $attributes = []): ProductImage
    {
        $image = ProductImage::factory()->for($product)->create($attributes);
        Storage::disk($image->disk)->put($image->path, 'image bytes');

        return $image;
    }
}
