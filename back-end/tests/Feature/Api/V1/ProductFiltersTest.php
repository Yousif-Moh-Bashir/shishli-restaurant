<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_show_returns_uuid_and_fixed_price_without_internal_identifiers(): void
    {
        $product = Product::factory()->unavailable()->create(['base_price' => '19.99']);
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()
            ->assertJsonPath('data.id', $product->uuid)->assertJsonPath('data.price', '19.99')
            ->assertJsonPath('data.is_available', false)->assertJsonPath('data.category.id', $product->category->uuid)
            ->assertJsonMissingPath('data.category_id')->assertJsonMissingPath('data.deleted_at');
        $this->getJson('/api/v1/products/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
    }

    public function test_deleted_category_hides_products_from_public_list_and_show(): void
    {
        $product = Product::factory()->create();
        $product->category->delete();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_category_filter_accepts_slug_or_public_uuid(): void
    {
        $product = Product::factory()->create();
        Product::factory()->create();
        foreach ([$product->category->slug, $product->category->uuid] as $key) {
            $this->getJson('/api/v1/products?'.http_build_query(['category' => $key]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->uuid);
        }
    }

    #[DataProvider('searchFields')]
    public function test_search_matches_name_sku_and_descriptions(string $field): void
    {
        $product = Product::factory()->create([$field => 'شيش-001']);
        Product::factory()->create();
        $this->getJson('/api/v1/products?'.http_build_query(['search' => 'شيش-001']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->uuid);
    }

    public static function searchFields(): array
    {
        return ['name' => ['name'], 'sku' => ['sku'], 'short description' => ['short_description'], 'description' => ['description']];
    }

    public function test_search_cannot_escape_public_visibility_constraints(): void
    {
        Product::factory()->inactive()->create(['name' => 'Needle', 'sku' => 'Needle']);
        Product::factory()->for(Category::factory()->inactive())->create(['sku' => 'Needle-2']);
        Product::factory()->create();
        $this->getJson('/api/v1/products?search=Needle')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?'.http_build_query(['search' => "' OR 1=1 --"]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    }

    #[DataProvider('publicFlags')]
    public function test_public_filters_support_true_and_false_flags(string $filter, string $column, int $value): void
    {
        $product = Product::factory()->create([$column => (bool) $value]);
        Product::factory()->create([$column => ! (bool) $value]);
        $this->getJson('/api/v1/products?'.$filter.'='.$value)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->uuid);
    }

    public static function publicFlags(): array
    {
        return [
            'featured' => ['featured', 'is_featured', 1],
            'not featured' => ['featured', 'is_featured', 0],
            'available' => ['available', 'is_available', 1],
            'unavailable' => ['available', 'is_available', 0],
        ];
    }

    #[DataProvider('sorts')]
    public function test_only_declared_sorting_modes_order_products(string $sort, array $expectedNames): void
    {
        Product::factory()->create(['name' => 'B', 'base_price' => '20.00', 'sort_order' => 1, 'created_at' => '2026-01-01 00:00:00']);
        Product::factory()->create(['name' => 'A', 'base_price' => '5.00', 'sort_order' => 1, 'created_at' => '2026-01-03 00:00:00']);
        Product::factory()->create(['name' => 'C', 'base_price' => '10.00', 'sort_order' => 0, 'created_at' => '2026-01-02 00:00:00']);
        $response = $this->getJson('/api/v1/products?sort='.$sort)->assertOk();
        $this->assertSame($expectedNames, array_column($response->json('data'), 'name'));
    }

    public static function sorts(): array
    {
        return [
            'default' => ['default', ['C', 'A', 'B']],
            'ascending price' => ['price_asc', ['A', 'C', 'B']],
            'descending price' => ['price_desc', ['B', 'C', 'A']],
            'name' => ['name', ['A', 'B', 'C']],
            'latest' => ['latest', ['A', 'C', 'B']],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_public_filters_return_422(string $query, string $field): void
    {
        $this->getJson('/api/v1/products?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'arbitrary column' => ['sort=category_id', 'sort'],
            'sql sort' => ['sort=price%20desc%3Bdrop%20table%20products', 'sort'],
            'sort array' => ['sort[]=name', 'sort'],
            'large page size' => ['per_page=1000000', 'per_page'],
            'zero page size' => ['per_page=0', 'per_page'],
            'fractional page size' => ['per_page=1.5', 'per_page'],
            'zero page' => ['page=0', 'page'],
            'available flag' => ['available=maybe', 'available'],
            'featured flag' => ['featured=maybe', 'featured'],
            'search array' => ['search[]=x', 'search'],
            'category array' => ['category[]=x', 'category'],
        ];
    }

    public function test_pagination_has_twenty_by_default_and_honors_custom_page_size(): void
    {
        Product::factory()->count(23)->create();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/v1/products?page=2&per_page=10')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonCount(23, 'data')->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_combines_search_category_and_false_flags_without_public_visibility_scope(): void
    {
        $this->authorizeView();
        $category = Category::factory()->inactive()->create();
        $product = Product::factory()->for($category)->inactive()->unavailable()->featured()->create(['sku' => 'MATCH-001']);
        Product::factory()->for($category)->featured()->create(['sku' => 'MATCH-002']);
        Product::factory()->inactive()->unavailable()->featured()->create(['sku' => 'MATCH-003']);
        $query = http_build_query([
            'category' => $category->uuid, 'search' => 'MATCH', 'is_active' => 0,
            'is_available' => 0, 'is_featured' => 1, 'per_page' => 1,
        ]);
        $this->getJson('/api/v1/admin/products?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->uuid)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/products?is_active=yes&is_available=yes&is_featured=yes&per_page=101&sort=category_id')
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active', 'is_available', 'is_featured', 'per_page', 'sort']);
    }

    public function test_public_and_admin_lists_eager_load_categories_in_one_query(): void
    {
        $this->authorizeView();
        Product::factory()->count(6)->create();
        foreach (['/api/v1/products', '/api/v1/admin/products'] as $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson($url)->assertOk()->assertJsonCount(6, 'data');
                $categoryQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select * from "categories"'));
                $this->assertCount(1, $categoryQueries);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }

    public function test_product_scopes_combine_active_available_and_featured(): void
    {
        $matching = Product::factory()->featured()->create();
        Product::factory()->featured()->inactive()->create();
        Product::factory()->featured()->unavailable()->create();
        Product::factory()->create();
        $this->assertSame([$matching->id], Product::active()->available()->featured()->pluck('id')->all());
    }

    private function authorizeView(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo('products.view');
        $this->withToken($user->createToken('products')->plainTextToken);
    }
}
