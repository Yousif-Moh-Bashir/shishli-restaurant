<?php

namespace Tests\Feature;

use App\Enums\PageStatus;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sitemap_contains_only_public_routes_and_published_products_and_pages(): void
    {
        config(['seo.site_url' => 'https://shishlifresh.com']);
        $visible = Product::factory()->create();
        $unavailable = Product::factory()->unavailable()->create();
        $inactive = Product::factory()->inactive()->create();
        $hiddenCategory = Product::factory()->for(Category::factory()->inactive())->create();
        $deleted = Product::factory()->create();
        $deleted->delete();
        $deletedCategory = Product::factory()->create();
        $deletedCategory->category->delete();
        Page::factory()->create(['key' => 'about']);
        Page::factory()->draft()->create(['key' => 'contact']);
        Page::factory()->create(['key' => 'gallery', 'published_at' => now()->addDay()]);
        Page::factory()->create(['key' => 'private-page']);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->streamedContent());
        $this->assertNotFalse($xml);
        $urls = array_map(fn ($entry): string => (string) $entry->loc, iterator_to_array($xml->url, false));
        $this->assertEqualsCanonicalizing([
            'https://shishlifresh.com/', 'https://shishlifresh.com/menu',
            'https://shishlifresh.com/branches', 'https://shishlifresh.com/offers',
            'https://shishlifresh.com/about',
            'https://shishlifresh.com/product/'.$visible->uuid,
            'https://shishlifresh.com/product/'.$unavailable->uuid,
        ], $urls);
        foreach ([$inactive, $hiddenCategory, $deleted, $deletedCategory] as $product) {
            $this->assertStringNotContainsString($product->uuid, $response->streamedContent());
        }
        $this->assertStringContainsString($visible->updated_at->toAtomString(), $response->streamedContent());
    }

    public function test_sitemap_reflects_publication_changes_without_stale_cache(): void
    {
        $this->travelTo(now()->startOfSecond());
        $page = Page::factory()->create(['key' => 'about', 'published_at' => now()->addHour()]);
        $product = Product::factory()->create();
        $this->assertStringNotContainsString('/about', $this->get('/sitemap.xml')->streamedContent());
        $this->travel(2)->hours();
        $this->assertStringContainsString('/about', $this->get('/sitemap.xml')->streamedContent());
        $page->update(['status' => PageStatus::Draft]);
        $product->update(['is_active' => false]);
        $xml = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('/about', $xml);
        $this->assertStringNotContainsString($product->uuid, $xml);
    }

    public function test_canonical_domain_is_configured_in_sitemap_and_robots_not_taken_from_request_host(): void
    {
        config(['seo.site_url' => 'https://restaurant.example/']);
        $xml = $this->get('http://untrusted.example/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('<loc>https://restaurant.example/menu</loc>', $xml);
        $this->assertStringNotContainsString('untrusted.example', $xml);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: https://restaurant.example/sitemap.xml', false);
    }

    public function test_robots_excludes_private_routes_but_allows_public_api_and_frontend_assets(): void
    {
        config(['seo.site_url' => 'https://shishlifresh.com']);
        $response = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        foreach (['/admin', '/driver', '/account', '/checkout', '/track-order', '/order-success'] as $path) {
            $response->assertSee('Disallow: '.$path, false);
        }
        $response->assertSee('Sitemap: https://shishlifresh.com/sitemap.xml', false);
        foreach (['Disallow: /assets', 'Disallow: /src', 'Disallow: /api/'."\n", '*.js', '*.css'] as $rule) {
            $response->assertDontSee($rule, false);
        }
    }
}
