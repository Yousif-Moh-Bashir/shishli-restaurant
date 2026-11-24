<?php

namespace Tests\Feature;

use App\Enums\PageStatus;
use App\Enums\SettingType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\RestaurantSetting;
use App\Services\RestaurantSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHtmlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.prerender' => true, 'seo.frontend_html' => base_path('../front-end/index.html'), 'seo.site_url' => 'https://shishlifresh.com']);
    }

    public function test_public_catalog_is_generated_without_branch_or_commerce_state_and_updates_immediately(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'name' => 'طبق <script> للاختبار', 'short_description' => 'وصف منشور', 'base_price' => '987.65', 'is_featured' => true,
        ]);
        ProductImage::factory()->for($product)->create(['path' => 'products/seo.webp', 'is_primary' => true]);
        $path = '/product/'.$product->uuid;
        $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<link rel="canonical" href="https://shishlifresh.com'.$path.'">', false)
            ->assertSee('طبق &lt;script&gt; للاختبار', false)->assertSee('وصف منشور')->assertSee('/storage/products/seo.webp')
            ->assertDontSee('987.65')->assertDontSee('available')->assertDontSee('noindex')->assertDontSee('<script> للاختبار', false);
        $this->get('/menu')->assertOk()->assertSee('href="'.$path.'"', false);
        $this->get('/')->assertOk()->assertSee('href="'.$path.'"', false);
        $product->update(['name' => 'اسم جديد']);
        $this->get($path)->assertOk()->assertSee('اسم جديد')->assertDontSee('للاختبار');
        $product->update(['is_active' => false]);
        $this->get($path)->assertNotFound()->assertDontSee('اسم جديد');
        $product->update(['is_active' => true]);
        $category->update(['is_active' => false]);
        $this->get($path)->assertNotFound()->assertDontSee('اسم جديد');
        $this->get('/menu')->assertOk()->assertDontSee($path);
        $this->assertStringNotContainsString($path, $this->get('/sitemap.xml')->streamedContent());
    }

    public function test_cms_visibility_canonical_redirect_and_missing_routes(): void
    {
        $page = Page::factory()->create(['key' => 'about', 'status' => PageStatus::Published, 'published_at' => null, 'meta_title' => 'عنوان عن المطعم']);
        $this->get('/about')->assertOk()->assertSee('عنوان عن المطعم')->assertSee('https://shishlifresh.com/about');
        $this->get('/ABOUT?source=nav')->assertStatus(301)->assertHeader('Location', '/about?source=nav');
        $page->update(['published_at' => now()->addDay()]);
        $this->get('/about')->assertNotFound()->assertDontSee('عنوان عن المطعم');
        $this->get('/')->assertOk()->assertDontSee('href="/about"', false);
        $this->get('/product/not-a-uuid')->assertNotFound();
        $this->get('/missing-page')->assertNotFound();
        $this->getJson('/api/missing-page')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_missing_build_fails_closed_and_feature_flag_restores_spa_handoff(): void
    {
        config(['seo.frontend_html' => base_path('missing-seo-shell.html')]);
        $this->get('/menu')->assertStatus(503)->assertHeader('Retry-After', '60')->assertDontSee('id="prerender-data"', false);
        config(['seo.prerender' => false]);
        $this->get('/menu')->assertOk()->assertHeader('X-Accel-Redirect', '/index.html');
    }

    public function test_grills_category_and_structured_data_use_only_published_catalogue_and_real_delivery_flags(): void
    {
        $grills = Category::factory()->create(['slug' => 'allhom', 'name' => 'اللحوم']);
        $drinks = Category::factory()->create(['slug' => 'mshrobat']);
        $item = Product::factory()->for($grills)->create(['name' => 'نفر كباب لحم', 'short_description' => 'كباب مع فلفل مشوي', 'base_price' => '987.65']);
        ProductImage::factory()->for($item)->create(['path' => 'products/grill.webp', 'is_primary' => true]);
        Product::factory()->for($grills)->inactive()->create(['name' => 'طبق خاص غير منشور']);
        Product::factory()->for($drinks)->create(['name' => 'مشروب المانجو']);
        Branch::factory()->create(['name' => 'فرع المصيف', 'city' => 'تبوك', 'supports_delivery' => true, 'accepts_orders' => true]);
        app(RestaurantSettingsService::class)->forget();

        $this->get('/grills-tabuk')->assertOk()->assertSee('مشويات وكباب في تبوك')->assertSee('/storage/products/grill.webp')
            ->assertSee($item->uuid)->assertDontSee('طبق خاص غير منشور')->assertDontSee('مشروب المانجو')->assertDontSee('987.65')
            ->assertDontSee('aggregateRating')->assertSee('MenuItem')->assertSee('href="https://shishlifresh.com/grills-tabuk"', false);
        RestaurantSetting::create(['key' => 'delivery.enabled', 'value' => false, 'type' => SettingType::Boolean,
            'group' => 'delivery', 'is_public' => true]);
        app(RestaurantSettingsService::class)->forget();
        $this->get('/grills-tabuk')->assertOk()->assertSee('التوصيل غير متاح حاليًا عبر الموقع');
        $this->getJson('/api/v1/catalog-page/menu/category/allhom')->assertOk()
            ->assertJsonPath('data.schema.@type', 'MenuSection')->assertJsonPath('data.items.0.label', $item->name)
            ->assertJsonCount(1, 'data.items');
        $this->get('/product/'.$item->uuid)->assertOk()->assertSee('MenuItem')->assertSee('href="/menu/category/allhom"', false);
        $xml = $this->get('/sitemap.xml')->streamedContent();
        $this->assertStringContainsString('/grills-tabuk', $xml);
        $this->assertStringContainsString('/menu/category/allhom', $xml);
        $item->update(['is_active' => false]);
        $this->get('/menu/category/allhom')->assertNotFound();
        $this->assertStringNotContainsString('/menu/category/allhom', $this->get('/sitemap.xml')->streamedContent());
    }
}
