<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\BranchProductSeeder;
use Database\Seeders\BranchSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchProductTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_routes_require_authentication_and_exact_permission(string $method, string $suffix, string $permission, int $status): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        if ($method !== 'POST' || $suffix === '/bulk') {
            $branch->products()->attach($product);
        }
        $url = $this->base($branch).str_replace('{product}', $product->uuid, $suffix);
        $payload = $suffix === '/bulk' ? ['products' => [['product_uuid' => $product->uuid]]] : ['product_uuid' => $product->uuid];
        $this->json($method, $url, $payload)->assertUnauthorized();
        $this->authorizeBranchProducts('products.update');
        $this->json($method, $url, $payload)->assertForbidden();
        $this->authorizeBranchProducts($permission);
        $this->json($method, $url, $payload)->assertStatus($status);
    }

    public static function endpoints(): array
    {
        return [
            'index' => ['GET', '', 'branches.view', 200],
            'attach' => ['POST', '', 'branches.products.manage', 201],
            'update' => ['PATCH', '/{product}', 'branches.products.manage', 200],
            'detach' => ['DELETE', '/{product}', 'branches.products.manage', 200],
            'bulk' => ['POST', '/bulk', 'branches.products.manage', 200],
        ];
    }

    public function test_attach_preserves_base_price_and_rejects_duplicate_without_accepting_internal_fields(): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['base_price' => '20.00']);
        $this->postJson($this->base($branch), [
            'product_uuid' => $product->uuid, 'price_override' => '19.99', 'is_available' => false,
            'branch_id' => 999, 'product_id' => 999, 'id' => 999,
        ])->assertCreated()->assertJsonPath('data.product.id', $product->uuid)
            ->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonPath('data.price_override', '19.99')
            ->assertJsonPath('data.effective_price', '19.99')->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.global_is_available', true)->assertJsonPath('data.effective_is_available', false)
            ->assertJsonMissingPath('data.branch_id')->assertJsonMissingPath('data.product_id')->assertJsonMissingPath('data.id');
        $this->assertDatabaseHas('branch_products', ['branch_id' => $branch->id, 'product_id' => $product->id, 'is_available' => false]);
        $this->assertSame('20.00', $product->fresh()->base_price);
        $this->postJson($this->base($branch), ['product_uuid' => $product->uuid])->assertUnprocessable()->assertJsonValidationErrors('product_uuid');
        $this->assertDatabaseCount('branch_products', 1);
    }

    public function test_attach_defaults_and_inactive_products_are_allowed_for_admin(): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $product = Product::factory()->inactive()->create(['base_price' => '20.00']);
        $this->postJson($this->base($branch), ['product_uuid' => strtoupper($product->uuid)])
            ->assertCreated()->assertJsonPath('data.price_override', null)->assertJsonPath('data.effective_price', '20.00')
            ->assertJsonPath('data.is_available', true)->assertJsonPath('data.effective_is_available', false);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_attach_data_returns_422_without_changes(array $changes, string $field): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->postJson($this->base($branch), array_replace(['product_uuid' => $product->uuid], $changes))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('branch_products', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'missing uuid' => [['product_uuid' => null], 'product_uuid'],
            'invalid uuid' => [['product_uuid' => 'invalid'], 'product_uuid'],
            'unknown uuid' => [['product_uuid' => '11111111-1111-4111-8111-111111111111'], 'product_uuid'],
            'negative' => [['price_override' => '-0.01'], 'price_override'],
            'precision' => [['price_override' => '12.001'], 'price_override'],
            'overflow' => [['price_override' => '100000000.00'], 'price_override'],
            'text price' => [['price_override' => 'free'], 'price_override'],
            'invalid boolean' => [['is_available' => 'yes'], 'is_available'],
        ];
    }

    public function test_deleted_products_and_branches_cannot_be_attached(): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $product->delete();
        $this->postJson($this->base($branch), ['product_uuid' => $product->uuid])->assertUnprocessable();
        $branch->delete();
        $this->postJson($this->base($branch), ['product_uuid' => $product->uuid])->assertNotFound();
        $this->assertDatabaseCount('branch_products', 0);
    }

    public function test_patch_preserves_omitted_fields_and_null_restores_current_base_price(): void
    {
        $this->authorizeBranchProducts();
        $assignment = BranchProduct::factory()->withPriceOverride('22.00')->unavailable()->create();
        $url = $this->base($assignment->branch).'/'.$assignment->product->uuid;
        $this->patchJson($url, ['price_override' => '0.00', 'product_id' => 999, 'branch_id' => 999])
            ->assertOk()->assertJsonPath('data.effective_price', '0.00')->assertJsonPath('data.is_available', false);
        $this->patchJson($url, ['is_available' => true])
            ->assertOk()->assertJsonPath('data.effective_price', '0.00')->assertJsonPath('data.effective_is_available', true);
        $assignment->product->update(['base_price' => '25.50']);
        $this->patchJson($url, ['price_override' => null])
            ->assertOk()->assertJsonPath('data.price_override', null)->assertJsonPath('data.effective_price', '25.50');
        $this->patchJson($url, ['price_override' => '-1', 'is_available' => false])->assertUnprocessable();
        $this->assertTrue($assignment->fresh()->is_available);
        $this->assertNull($assignment->fresh()->price_override);
    }

    public function test_wrong_branch_cannot_update_or_detach_and_detach_preserves_other_records(): void
    {
        $this->authorizeBranchProducts();
        $assignment = BranchProduct::factory()->create();
        $other = Branch::factory()->create();
        $product = $assignment->product;
        $url = $this->base($other).'/'.$product->uuid;
        $this->patchJson($url, ['is_available' => false])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $other->products()->attach($product);
        $this->deleteJson($this->base($assignment->branch).'/'.$product->uuid)->assertOk();
        $this->assertModelMissing($assignment);
        $this->assertNotSoftDeleted($product);
        $this->assertNotSoftDeleted($assignment->branch);
        $this->assertSame(1, $other->products()->count());
    }

    public function test_bulk_upserts_atomically_preserving_unmentioned_links_and_omitted_fields(): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $existing = BranchProduct::factory()->for($branch)->unavailable()->withPriceOverride('30.00')->create();
        $untouched = BranchProduct::factory()->for($branch)->withPriceOverride('40.00')->create();
        $new = Product::factory()->create();
        $this->postJson($this->base($branch).'/bulk', ['products' => [
            ['product_uuid' => $existing->product->uuid, 'price_override' => null],
            ['product_uuid' => $new->uuid, 'price_override' => '19.99'],
        ]])->assertOk()->assertJsonCount(2, 'data');
        $this->assertNull($existing->fresh()->price_override);
        $this->assertFalse($existing->fresh()->is_available);
        $this->assertSame('40.00', $untouched->fresh()->price_override);
        $this->assertSame(3, $branch->products()->count());
        $this->assertSame('19.99', BranchProduct::where('product_id', $new->id)->first()->price_override);
        $this->postJson($this->base($branch).'/bulk', ['products' => [
            ['product_uuid' => $existing->product->uuid, 'is_available' => true],
            ['product_uuid' => $new->uuid],
        ]])->assertOk();
        $this->assertSame(3, $branch->products()->count());
        $this->assertSame('19.99', BranchProduct::where('product_id', $new->id)->first()->price_override);
        $this->assertTrue($existing->fresh()->is_available);
    }

    public function test_bulk_missing_or_deleted_product_rolls_back_all_changes(): void
    {
        $this->authorizeBranchProducts();
        $assignment = BranchProduct::factory()->withPriceOverride('22.00')->create();
        $deleted = Product::factory()->create();
        $deleted->delete();
        foreach ([(string) Str::uuid(), $deleted->uuid] as $uuid) {
            $this->postJson($this->base($assignment->branch).'/bulk', ['products' => [
                ['product_uuid' => $assignment->product->uuid, 'price_override' => '99.00', 'is_available' => false],
                ['product_uuid' => $uuid],
            ]])->assertUnprocessable()->assertJsonValidationErrors('products.1.product_uuid');
            $this->assertSame('22.00', $assignment->fresh()->price_override);
            $this->assertTrue($assignment->fresh()->is_available);
            $this->assertDatabaseCount('branch_products', 1);
        }
    }

    public function test_bulk_rejects_duplicate_excess_empty_and_unsafe_items(): void
    {
        $this->authorizeBranchProducts();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $url = $this->base($branch).'/bulk';
        $this->postJson($url, ['products' => [
            ['product_uuid' => $product->uuid], ['product_uuid' => strtoupper($product->uuid)],
        ]])->assertUnprocessable()->assertJsonValidationErrors('products.0.product_uuid');
        $this->postJson($url, ['products' => array_fill(0, 101, ['product_uuid' => $product->uuid])])
            ->assertUnprocessable()->assertJsonValidationErrors('products');
        $this->postJson($url, ['products' => []])->assertUnprocessable()->assertJsonValidationErrors('products');
        $this->postJson($url, ['products' => [['product_uuid' => $product->uuid, 'branch_id' => 999]]])
            ->assertUnprocessable()->assertJsonValidationErrors('products.0');
        $this->postJson($url, ['products' => [['product_uuid' => $product->uuid, 'price_override' => '1.001']]])
            ->assertUnprocessable()->assertJsonValidationErrors('products.0.price_override');
        $this->assertDatabaseCount('branch_products', 0);
    }

    public function test_admin_index_filters_paginates_and_includes_prices_category_and_primary_image(): void
    {
        $this->authorizeBranchProducts('branches.view');
        $branch = Branch::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->unavailable()->create(['name' => 'Special chicken', 'sku' => 'SH-001', 'base_price' => '20.00']);
        $image = ProductImage::factory()->for($product)->create(['is_primary' => true]);
        BranchProduct::factory()->for($branch)->for($product)->withPriceOverride('22.00')->create();
        BranchProduct::factory()->for($branch)->unavailable()->create();
        BranchProduct::factory()->create();
        $this->getJson($this->base($branch).'?'.http_build_query([
            'search' => 'SH-001', 'category' => $category->uuid, 'is_available' => 1, 'per_page' => 1,
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.product.category.id', $category->uuid)
            ->assertJsonPath('data.0.product.primary_image.id', $image->uuid)
            ->assertJsonPath('data.0.effective_price', '22.00')->assertJsonPath('data.0.global_is_available', false)
            ->assertJsonPath('data.0.is_available', true)->assertJsonPath('data.0.effective_is_available', false)
            ->assertJsonMissingPath('data.0.product.option_groups')->assertJsonMissingPath('data.0.product.images');
        $this->getJson($this->base($branch).'?is_available=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base($branch).'?page=0&per_page=101&is_available=yes&search[]=x&category[]=x')
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page', 'is_available', 'search', 'category']);
    }

    public function test_index_excludes_soft_deleted_products_and_does_not_add_queries_per_product(): void
    {
        $this->authorizeBranchProducts('branches.view');
        $branch = Branch::factory()->create();
        BranchProduct::factory()->for($branch)->create();
        $deleted = BranchProduct::factory()->for($branch)->create();
        $deleted->product->delete();
        DB::enableQueryLog();
        $this->getJson($this->base($branch))->assertOk()->assertJsonCount(1, 'data');
        $firstCount = count(DB::getQueryLog());
        BranchProduct::factory()->count(8)->for($branch)->create();
        DB::flushQueryLog();
        $this->getJson($this->base($branch))->assertOk()->assertJsonCount(9, 'data');
        $this->assertLessThanOrEqual($firstCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_branch_product_seeder_is_idempotent_and_preserves_admin_changes(): void
    {
        $this->seed([BranchSeeder::class, ProductSeeder::class, BranchProductSeeder::class]);
        $branch = Branch::where('slug', 'al-masif')->firstOrFail();
        $product = Product::where('slug', 'shish-tawook')->firstOrFail();
        $assignment = BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('22.00', $assignment->price_override);
        $assignment->update(['price_override' => '23.00', 'is_available' => false]);
        $this->seed(BranchProductSeeder::class);
        $this->assertDatabaseCount('branch_products', 5);
        $this->assertSame('23.00', $assignment->fresh()->price_override);
        $this->assertFalse($assignment->fresh()->is_available);
    }

    private function base(Branch $branch): string
    {
        return '/api/v1/admin/branches/'.$branch->uuid.'/products';
    }

    private function authorizeBranchProducts(string $permission = 'branches.products.manage'): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->actingAs($user, 'sanctum');
    }
}
