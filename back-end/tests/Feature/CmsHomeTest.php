<?php

namespace Tests\Feature;

use App\Actions\Settings\UpdateRestaurantSettingsAction;
use App\Enums\PageStatus;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\GalleryItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\CmsPageSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CmsHomeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(CmsPageSeeder::class);
        Page::where('key', 'home')->firstOrFail()->update(['status' => PageStatus::Published]);
    }

    public function test_home_returns_cms_banners_limited_gallery_and_live_domain_data(): void
    {
        $branch = Branch::factory()->create();
        Banner::factory()->create();
        GalleryItem::factory()->count(8)->create();
        $offer = Promotion::factory()->create();
        $response = $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk()->assertJsonPath('data.page.key', 'home')->assertJsonCount(1, 'data.banners')->assertJsonCount(6, 'data.gallery_preview')->assertJsonPath('data.active_offers.0.id', $offer->uuid)->assertJsonPath('data.branches.0.branch.id', $branch->uuid);
        $this->assertNotEmpty($response->json('data.restaurant.restaurant.name'));
        $response->assertJsonCount(7, 'data.branches.0.business_hours.days');
    }

    public function test_featured_products_reuse_menu_branch_price_and_availability(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['is_featured' => true]);
        BranchProduct::factory()->for($branch)->for($product)->create(['price_override' => '31.00']);
        BranchProduct::factory()->for($branch)->create();
        $menu = $this->getJson('/api/v1/menu?branch='.$branch->uuid)->assertOk();
        $home = $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk()->assertJsonCount(1, 'data.featured_products')->assertJsonPath('data.featured_products.0.id', $product->uuid);
        $menuProduct = collect($menu->json('data.categories'))->flatMap(fn ($category) => $category['products'] ?? [])->firstWhere('id', $product->uuid);
        $this->assertSame($menuProduct['price'], $home->json('data.featured_products.0.price'));
        $this->assertSame($menuProduct['is_available'], $home->json('data.featured_products.0.is_available'));
    }

    public function test_without_branch_home_does_not_invent_product_prices(): void
    {
        Product::factory()->create(['is_featured' => true]);
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(0, 'data.featured_products')->assertJsonPath('data.branch_uuid', null);
    }

    public function test_branch_validation_rejects_invalid_and_inactive_branches(): void
    {
        $this->getJson('/api/v1/home?branch=bad')->assertUnprocessable();
        $branch = Branch::factory()->create(['is_active' => false]);
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertUnprocessable();
    }

    public function test_home_public_offers_match_promotion_domain_and_branch_scope(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $offer = Promotion::factory()->create();
        $targeted = Promotion::factory()->create();
        $targeted->branches()->attach($other);
        Promotion::factory()->coupon()->create();
        Promotion::factory()->future()->create();
        Promotion::factory()->expired()->create();
        Promotion::factory()->inactive()->create();
        Promotion::factory()->create(['usage_limit' => 1, 'used_count' => 1]);
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertJsonCount(1, 'data.active_offers')->assertJsonPath('data.active_offers.0.id', $offer->uuid);
        $this->getJson('/api/v1/promotions?branch_uuid='.$branch->uuid)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $offer->uuid);
    }

    public function test_inventory_changes_are_not_hidden_by_cms_fragment_cache(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['is_featured' => true, 'tracks_inventory' => true]);
        BranchProduct::factory()->for($branch)->for($product)->create();
        $inventory = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => '5.000', 'reserved' => '0.000']);
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk()->assertJsonPath('data.featured_products.0.is_available', true);
        $inventory->forceFill(['on_hand' => '0.000'])->save();
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk()->assertJsonPath('data.featured_products.0.is_available', false);
    }

    public function test_home_branch_status_is_live(): void
    {
        $branch = Branch::factory()->create();
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.branches.0.status.accepts_orders', true);
        $branch->update(['accepts_orders' => false]);
        $this->getJson('/api/v1/home')->assertJsonPath('data.branches.0.status.accepts_orders', false);
    }

    public function test_home_gallery_limit_and_inactive_sections_are_respected(): void
    {
        Page::where('key', 'home')->firstOrFail()->sections()->where('key', 'gallery_preview')->firstOrFail()->update(['data' => ['limit' => 2]]);
        GalleryItem::factory()->count(5)->create();
        $this->getJson('/api/v1/home')->assertJsonCount(2, 'data.gallery_preview');
        Page::where('key', 'home')->firstOrFail()->sections()->where('key', 'gallery_preview')->firstOrFail()->update(['is_active' => false]);
        $this->getJson('/api/v1/home')->assertJsonCount(0, 'data.gallery_preview');
    }

    public function test_home_requires_published_page(): void
    {
        Page::where('key', 'home')->firstOrFail()->update(['status' => PageStatus::Draft]);
        $this->getJson('/api/v1/home')->assertNotFound();
    }

    public function test_seo_name_fallback_reflects_settings_update_with_warm_page_cache(): void
    {
        $this->getJson('/api/v1/home')->assertOk();
        app(UpdateRestaurantSettingsAction::class)->handle(['restaurant.name' => 'اسم جديد'], User::factory()->create());
        $response = $this->getJson('/api/v1/home')->assertOk();
        $this->assertStringContainsString('اسم جديد', $response->json('data.page.seo.title'));
    }

    public function test_home_query_count_does_not_grow_per_product_or_branch(): void
    {
        $branch = Branch::factory()->create();
        BranchProduct::factory()->for($branch)->for(Product::factory()->state(['is_featured' => true]))->create();
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (Product::factory()->count(5)->create(['is_featured' => true]) as $product) {
            BranchProduct::factory()->for($branch)->for($product)->create();
        } Branch::factory()->count(3)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/home?branch='.$branch->uuid)->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
    }
}
