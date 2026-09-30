<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_products_use_internal_ids_and_public_uuids_with_category_relationship(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $second = Product::factory()->for($category)->create();

        $this->assertIsInt($product->id);
        $this->assertGreaterThan($product->id, $second->id);
        $this->assertTrue(Str::isUuid($product->uuid));
        $this->assertNotSame($product->uuid, $second->uuid);
        $this->assertSame($category->id, $product->category_id);
        $this->assertTrue($product->category->is($category));
        $this->assertSame([$product->id, $second->id], $category->products()->orderBy('id')->pluck('id')->all());
        $this->assertSame($product->id, (new Product)->resolveRouteBinding($product->uuid)->id);
        $this->assertArrayNotHasKey('id', $product->toArray());
        $this->assertArrayNotHasKey('category_id', $product->toArray());
    }

    #[DataProvider('prices')]
    public function test_price_preserves_two_decimal_places(string $price): void
    {
        $product = Product::factory()->create(['base_price' => $price]);

        $this->assertSame($price, $product->fresh()->base_price);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function prices(): array
    {
        return ['zero' => ['0.00'], 'fractional' => ['19.99'], 'small' => ['0.10'], 'maximum' => ['99999999.99']];
    }

    public function test_product_defaults_and_nullable_fields_are_preserved(): void
    {
        $product = Product::factory()->create()->refresh();

        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_available);
        $this->assertFalse($product->is_featured);
        $this->assertSame(0, $product->sort_order);
        $this->assertNull($product->sku);
        $this->assertNull($product->short_description);
        $this->assertNull($product->description);
        $this->assertNull($product->preparation_time);
    }

    public function test_product_details_and_flags_can_change_without_changing_uuid(): void
    {
        $product = Product::factory()->create();
        $uuid = $product->uuid;

        $product->update([
            'sku' => '00125',
            'short_description' => 'Short description',
            'description' => 'Full description',
            'is_active' => false,
            'is_available' => false,
            'is_featured' => true,
            'sort_order' => 20,
            'preparation_time' => 15,
        ]);
        $product->refresh();

        $this->assertSame($uuid, $product->uuid);
        $this->assertSame('00125', $product->sku);
        $this->assertSame('Short description', $product->short_description);
        $this->assertSame('Full description', $product->description);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->is_available);
        $this->assertTrue($product->is_featured);
        $this->assertSame(20, $product->sort_order);
        $this->assertSame(15, $product->preparation_time);
    }

    #[DataProvider('uniqueFields')]
    public function test_duplicate_identifiers_are_rejected(string $field): void
    {
        $product = Product::factory()->create(['sku' => 'SKU-001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Product::factory()->create([$field => $product->{$field}]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uniqueFields(): array
    {
        return ['uuid' => ['uuid'], 'slug' => ['slug'], 'sku' => ['sku']];
    }

    public function test_product_cannot_reference_a_missing_category(): void
    {
        $this->expectException(QueryException::class);

        Product::factory()->create(['category_id' => 99999]);
    }

    public function test_database_prevents_deleting_a_category_with_products(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        $product->category->forceDelete();
    }

    public function test_api_returns_422_when_deleting_a_category_with_products(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->assignRole('manager');
        $product = Product::factory()->create(['is_active' => false]);
        $category = $product->category;

        $this->withToken($manager->createToken('test')->plainTextToken)
            ->deleteJson('/api/v1/admin/categories/'.$category->uuid)
            ->assertUnprocessable()->assertJsonPath('success', false)
            ->assertJsonPath('errors.category.0', 'لا يمكن حذف القسم لأنه يحتوي على منتجات.');

        $this->assertModelExists($category);
        $this->assertModelExists($product);
    }

    public function test_product_seeder_is_repeatable_and_populates_the_restaurant_menu(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('categories', 11);
        $product = Product::where('slug', 'shish-tawook')->sole();
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_available);
        $this->assertTrue($product->is_featured);
        $this->assertSame('20.00', $product->base_price);
        $this->assertSame('shish', $product->category->slug);
    }
}
