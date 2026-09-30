<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_each_admin_endpoint_requires_authentication_and_its_own_permission(string $method, bool $individual, string $permission): void
    {
        $product = Product::factory()->create();
        $url = '/api/v1/admin/products'.($individual ? '/'.$product->uuid : '');
        $payload = ['name' => 'Authorized', 'category_uuid' => $product->category->uuid, 'base_price' => '19.99'];
        $this->json($method, $url, $payload)->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(array_values(array_diff([
            'products.view', 'products.create', 'products.update', 'products.delete',
        ], [$permission])));
        $this->withToken($user->createToken('products')->plainTextToken);
        $this->json($method, $url, $payload)->assertForbidden();
        $this->assertDatabaseCount('products', 1);
        $this->assertNotSoftDeleted($product);
        $user->syncPermissions([$permission]);
        app('auth')->forgetGuards();

        $this->json($method, $url, $payload)->assertStatus($method === 'POST' ? 201 : 200);

        if ($method === 'DELETE') {
            $this->assertSoftDeleted($product);
        } elseif ($method !== 'GET') {
            $this->assertDatabaseHas('products', ['name' => 'Authorized', 'base_price' => '19.99']);
        }
    }

    public static function endpoints(): array
    {
        return [
            'index' => ['GET', false, 'products.view'],
            'show' => ['GET', true, 'products.view'],
            'create' => ['POST', false, 'products.create'],
            'put' => ['PUT', true, 'products.update'],
            'patch' => ['PATCH', true, 'products.update'],
            'delete' => ['DELETE', true, 'products.delete'],
        ];
    }

    public function test_create_uses_uuid_input_allows_inactive_category_and_ignores_protected_attributes(): void
    {
        $this->authorizeProducts('products.create');
        $category = Category::factory()->inactive()->create();
        $other = Category::factory()->create();
        $clientUuid = (string) Str::uuid();
        $response = $this->postJson('/api/v1/admin/products', [
            'category_uuid' => $category->uuid, 'name' => 'شيش طاووق', 'base_price' => '19.99',
            'sku' => null, 'id' => 9000, 'uuid' => $clientUuid, 'category_id' => $other->id,
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01', 'deleted_at' => '2000-01-01',
        ])->assertCreated()->assertJsonPath('data.price', '19.99')
            ->assertJsonPath('data.category.id', $category->uuid)->assertJsonPath('data.sku', null)
            ->assertJsonMissingPath('data.category_id')->assertJsonMissingPath('data.deleted_at');

        $product = Product::sole();
        $this->assertTrue(Str::isUuid($product->uuid));
        $this->assertNotSame($clientUuid, $product->uuid);
        $this->assertNotSame(9000, $product->id);
        $this->assertSame($product->uuid, $response->json('data.id'));
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('19.99', $product->base_price);
        $this->assertNotSame('2000-01-01', $product->created_at->toDateString());
        $this->assertNotSame('2000-01-01', $product->updated_at->toDateString());
        $this->assertNotSoftDeleted($product);
    }

    public function test_create_requires_name_category_and_price_with_arabic_errors(): void
    {
        $this->authorizeProducts('products.create');
        $this->postJson('/api/v1/admin/products', [])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'اسم المنتج مطلوب.')
            ->assertJsonPath('errors.category_uuid.0', 'القسم مطلوب.')
            ->assertJsonPath('errors.base_price.0', 'سعر المنتج مطلوب.');
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_create_and_update_reject_invalid_data_without_writing(string $field, mixed $value): void
    {
        $this->authorizeProducts('products.create', 'products.update');
        $product = Product::factory()->create();
        $payload = ['category_uuid' => $product->category->uuid, 'name' => 'Valid', 'base_price' => '20.00'];
        $before = $product->refresh()->getRawOriginal();

        $this->postJson('/api/v1/admin/products', array_replace($payload, [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('products', 1);
        $this->assertSame($before, $product->fresh()->getRawOriginal());
    }

    public static function invalidFields(): array
    {
        return [
            'null category' => ['category_uuid', null],
            'malformed category' => ['category_uuid', 'invalid'],
            'unknown category' => ['category_uuid', '00000000-0000-4000-8000-000000000001'],
            'null name' => ['name', null],
            'name type' => ['name', []],
            'name length' => ['name', str_repeat('a', 181)],
            'slug type' => ['slug', []],
            'slug format' => ['slug', 'invalid slug'],
            'slug length' => ['slug', str_repeat('a', 201)],
            'sku type' => ['sku', []],
            'sku length' => ['sku', str_repeat('a', 101)],
            'short description type' => ['short_description', []],
            'short description length' => ['short_description', str_repeat('a', 501)],
            'description type' => ['description', []],
            'description length' => ['description', str_repeat('a', 5001)],
            'null price' => ['base_price', null],
            'price type' => ['base_price', 'invalid'],
            'negative price' => ['base_price', '-0.01'],
            'overflow price' => ['base_price', '100000000.00'],
            'excess decimals' => ['base_price', '19.999'],
            'scientific notation' => ['base_price', '1e2'],
            'active flag' => ['is_active', 'yes'],
            'available flag' => ['is_available', 'yes'],
            'featured flag' => ['is_featured', 'yes'],
            'negative order' => ['sort_order', -1],
            'fractional order' => ['sort_order', 1.5],
            'overflow order' => ['sort_order', 2147483648],
            'preparation type' => ['preparation_time', 1.5],
            'negative preparation' => ['preparation_time', -1],
            'long preparation' => ['preparation_time', 1441],
        ];
    }

    public function test_soft_deleted_categories_are_rejected_for_create_and_update(): void
    {
        $this->authorizeProducts('products.create', 'products.update');
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $category->delete();
        $this->postJson('/api/v1/admin/products', [
            'name' => 'Invalid', 'base_price' => '20.00', 'category_uuid' => $category->uuid,
        ])->assertUnprocessable()->assertJsonPath('errors.category_uuid.0', 'القسم المحدد غير موجود.');
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['category_uuid' => $category->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('category_uuid');
        $this->assertSame($product->category_id, $product->fresh()->category_id);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_slug_generation_handles_arabic_empty_transliteration_and_existing_deleted_slugs(): void
    {
        $this->authorizeProducts('products.create');
        $category = Category::factory()->create();
        Product::factory()->for($category)->create(['slug' => 'grills'])->delete();
        Product::factory()->for($category)->create(['slug' => 'grills-2']);
        $payload = ['category_uuid' => $category->uuid, 'base_price' => '20.00', 'slug' => null];
        $this->postJson('/api/v1/admin/products', [...$payload, 'name' => 'Grills'])
            ->assertCreated()->assertJsonPath('data.slug', 'grills-3');
        $first = $this->postJson('/api/v1/admin/products', [...$payload, 'name' => 'شيش طاووق'])->assertCreated();
        $second = $this->postJson('/api/v1/admin/products', [...$payload, 'name' => 'شيش طاووق'])->assertCreated();
        $this->assertNotEmpty($first->json('data.slug'));
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
        Str::createRandomStringsUsing(fn (): string => 'aaaaaaaa');
        try {
            $this->postJson('/api/v1/admin/products', [...$payload, 'name' => '🍗'])
                ->assertCreated()->assertJsonPath('data.slug', 'product-aaaaaaaa');
            $this->postJson('/api/v1/admin/products', [...$payload, 'name' => '🍗'])
                ->assertCreated()->assertJsonPath('data.slug', 'product-aaaaaaaa-2');
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    #[DataProvider('uniqueFields')]
    public function test_identifiers_are_unique_on_create_update_and_after_soft_delete(string $field): void
    {
        $this->authorizeProducts('products.create', 'products.update');
        $existing = Product::factory()->create(['sku' => 'SH-001']);
        $other = Product::factory()->create(['sku' => 'SH-002']);
        $payload = [
            'category_uuid' => $existing->category->uuid, 'name' => 'Duplicate',
            'base_price' => '20.00', $field => $existing->{$field},
        ];
        $this->postJson('/api/v1/admin/products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/v1/admin/products/'.$other->uuid, [$field => $existing->{$field}])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $existing->delete();
        $this->postJson('/api/v1/admin/products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/v1/admin/products/'.$other->uuid, [$field => $existing->{$field}])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($other->{$field}, $other->fresh()->{$field});
        $this->assertDatabaseCount('products', 2);
    }

    public static function uniqueFields(): array
    {
        return ['slug' => ['slug'], 'sku' => ['sku']];
    }

    #[DataProvider('partialUpdates')]
    public function test_patch_updates_one_field_without_requiring_other_fields(string $field, mixed $value): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create(['sku' => 'SH-001', 'preparation_time' => 20]);
        $responseField = $field === 'base_price' ? 'price' : $field;
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, [$field => $value])
            ->assertOk()->assertJsonPath('data.'.$responseField, $value)
            ->assertJsonPath('data.category.id', $product->category->uuid);
        $fresh = $product->fresh();
        $this->assertSame($value, $fresh->{$field});
        $this->assertSame($product->category_id, $fresh->category_id);
        $this->assertSame($product->uuid, $fresh->uuid);
    }

    public static function partialUpdates(): array
    {
        return [
            'name' => ['name', 'Updated'],
            'price' => ['base_price', '19.99'],
            'unavailable' => ['is_available', false],
            'inactive' => ['is_active', false],
            'featured' => ['is_featured', true],
            'sort order' => ['sort_order', 10],
            'clear sku' => ['sku', null],
            'clear preparation time' => ['preparation_time', null],
        ];
    }

    public function test_update_preserves_own_identifiers_and_resolves_new_inactive_category(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create(['sku' => 'SH-001']);
        $category = Category::factory()->inactive()->create();
        $this->putJson('/api/v1/admin/products/'.$product->uuid, [
            'category_uuid' => $category->uuid, 'slug' => $product->slug, 'sku' => $product->sku,
            'id' => 9000, 'uuid' => (string) Str::uuid(), 'category_id' => 99999,
            'deleted_at' => '2000-01-01', 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
        ])->assertOk()->assertJsonPath('data.id', $product->uuid)
            ->assertJsonPath('data.category.id', $category->uuid);
        $fresh = $product->fresh();
        $this->assertSame($category->id, $fresh->category_id);
        $this->assertSame($product->id, $fresh->id);
        $this->assertSame($product->slug, $fresh->slug);
        $this->assertSame($product->sku, $fresh->sku);
        $this->assertSame($product->created_at->toISOString(), $fresh->created_at->toISOString());
        $this->assertNotSame('2000-01-01', $fresh->updated_at->toDateString());
        $this->assertNotSoftDeleted($product);
    }

    public function test_null_slug_regenerates_safely_and_nullable_skus_can_repeat(): void
    {
        $this->authorizeProducts('products.create', 'products.update');
        $product = Product::factory()->create(['name' => 'Grills']);
        Product::factory()->create(['slug' => 'grills']);
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['slug' => null])
            ->assertOk()->assertJsonPath('data.slug', 'grills-2');
        foreach (['A', 'B'] as $name) {
            $this->postJson('/api/v1/admin/products', [
                'category_uuid' => $product->category->uuid, 'name' => $name, 'base_price' => '0', 'sku' => null,
            ])->assertCreated()->assertJsonPath('data.price', '0.00')->assertJsonPath('data.sku', null);
        }
        $this->assertDatabaseCount('products', 4);
    }

    public function test_admin_sees_inactive_and_unavailable_products_and_paginates(): void
    {
        $this->authorizeProducts('products.view');
        $product = Product::factory()->inactive()->unavailable()->create(['sort_order' => 0]);
        Product::factory()->count(20)->create(['sort_order' => 1]);
        $deleted = Product::factory()->create();
        $deleted->delete();
        $this->getJson('/api/v1/admin/products')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.id', $product->uuid)->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.category.id', $product->category->uuid);
        $this->getJson('/api/v1/admin/products/'.$product->uuid)->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/admin/products?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/products/'.$product->id)->assertNotFound();
    }

    public function test_soft_delete_hides_product_preserves_images_and_releases_category_deletion(): void
    {
        $this->authorizeProducts('products.delete', 'products.view', 'products.update', 'categories.delete');
        $product = Product::factory()->create();
        $category = $product->category;
        $image = ProductImage::factory()->for($product)->create();
        $this->deleteJson('/api/v1/admin/categories/'.$category->uuid)->assertUnprocessable()
            ->assertJsonPath('errors.category.0', 'لا يمكن حذف القسم لأنه يحتوي على منتجات.');
        $this->deleteJson('/api/v1/admin/products/'.$product->uuid)->assertOk();
        $this->assertSoftDeleted($product);
        $this->assertModelExists($image);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound();
        $this->getJson('/api/v1/admin/products/'.$product->uuid)->assertNotFound();
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['name' => 'Invalid'])->assertNotFound();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.products_count', 0);
        $this->deleteJson('/api/v1/admin/categories/'.$category->uuid)->assertOk();
        $this->assertSoftDeleted($category);
    }

    public function test_product_seeder_preserves_prices_identifiers_and_deleted_records(): void
    {
        $this->seed(ProductSeeder::class);
        $product = Product::where('slug', 'shish-tawook')->sole();
        $product->update(['base_price' => '22.50']);
        $deleted = Product::where('slug', 'beef-kebab')->sole();
        $deleted->delete();
        $this->seed(ProductSeeder::class);
        $this->assertDatabaseCount('products', 5);
        $this->assertSame('22.50', $product->fresh()->base_price);
        $this->assertSame($product->uuid, $product->fresh()->uuid);
        $this->assertSoftDeleted($deleted);
    }

    private function authorizeProducts(string ...$permissions): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo($permissions);
        $this->withToken($user->createToken('products')->plainTextToken);
    }
}
