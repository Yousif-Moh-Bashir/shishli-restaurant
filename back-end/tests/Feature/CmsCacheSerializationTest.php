<?php

namespace Tests\Feature;

use App\Enums\BannerPlacement;
use App\Enums\PageSectionType;
use App\Enums\PageStatus;
use App\Models\Banner;
use App\Models\GalleryItem;
use App\Models\Page;
use App\Models\PageSection;
use App\Services\CmsContentService;
use Carbon\CarbonImmutable;
use Database\Seeders\CmsPageSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CmsCacheSerializationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertFalse(config('cache.serializable_classes'));

        // Only transport is simulated; RedisStore performs serialization and restricted unserialization.
        $bytes = new ArrayStore;
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $bytes->get($key));
        $connection->shouldReceive('setex')->andReturnUsing(fn (string $key, int $ttl, mixed $value): bool => $bytes->put($key, $value, $ttl));
        $connection->shouldReceive('set')->andReturnUsing(function (string $key, mixed $value, mixed ...$options) use ($bytes): bool {
            if ($options !== [] && $bytes->get($key) !== null) {
                return false;
            }

            return $bytes->put($key, $value, $options[1] ?? 0);
        });
        $connection->shouldReceive('del')->andReturnUsing(fn (string $key): int => (int) $bytes->forget($key));
        $connection->shouldReceive('eval')->andReturnUsing(function (string $script, int $keys, string $key, string $owner) use ($bytes): int {
            return $bytes->get($key) === $owner ? (int) $bytes->forget($key) : 0;
        });
        $redis = Mockery::mock(Factory::class);
        $redis->shouldReceive('connection')->with('cache')->andReturn($connection);
        Cache::extend('restricted-redis', fn () => Cache::repository(new RedisStore(
            $redis, 'cms-regression:', 'cache', config('cache.serializable_classes')
        )));
        config(['cache.default' => 'restricted-redis', 'cache.stores.restricted-redis' => ['driver' => 'restricted-redis']]);

        $this->travelTo(now()->startOfSecond());
        $this->seed(CmsPageSeeder::class);
        Page::where('key', 'home')->firstOrFail()->update(['status' => PageStatus::Published]);
    }

    public function test_store_really_disallows_php_objects(): void
    {
        $this->assertInstanceOf(RedisStore::class, Cache::getStore());
        Cache::put('serialization-probe', (object) ['value' => 'blocked'], 300);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('serialization-probe'));
    }

    public function test_home_and_page_survive_repeated_hits_with_relationships_casts_and_media(): void
    {
        $page = Page::where('key', 'home')->firstOrFail();
        $page->sections()->where('key', 'hero')->firstOrFail()->update([
            'title' => 'Cached hero', 'data' => ['image_path' => 'cms/pages/hero.jpg', 'primary_button_url' => '/menu'],
        ]);
        $page->sections()->where('key', 'features')->firstOrFail()->update(['is_active' => false]);
        PageSection::factory()->for($page)->create(['key' => 'unknown']);
        $hero = Banner::factory()->create(['mobile_image' => 'cms/banners/mobile.jpg']);
        $secondary = Banner::factory()->create(['placement' => BannerPlacement::HomeSecondary]);
        $gallery = GalleryItem::factory()->create();
        $first = $this->getJson('/api/v1/home')->assertOk()
            ->assertJsonPath('data.banners.0.id', $hero->uuid)
            ->assertJsonPath('data.secondary_banners.0.id', $secondary->uuid)
            ->assertJsonPath('data.gallery_preview.0.id', $gallery->uuid)
            ->assertJsonMissingPath('data.page.status')->assertJsonMissingPath('data.page.created_by');
        $this->assertStringEndsWith('/cms/banners/mobile.jpg', $first->json('data.banners.0.mobile_image_url'));
        $this->assertSame(['hero', 'about_preview', 'featured_menu', 'offers', 'gallery_preview', 'location', 'cta'], array_column($first->json('data.page.sections'), 'key'));
        $this->assertStringEndsWith('/cms/pages/hero.jpg', $first->json('data.page.sections.0.data.image_url'));
        $this->assertArrayNotHasKey('image_path', $first->json('data.page.sections.0.data'));

        // Bypass events to prove later requests hit the cached snapshot instead of reloading it.
        DB::table('pages')->where('id', $page->id)->update(['title' => 'Not cached']);
        DB::table('banners')->update(['title' => 'Not cached']);
        DB::table('gallery_items')->update(['title' => 'Not cached']);
        for ($hit = 0; $hit < 3; $hit++) {
            $this->getJson('/api/v1/home')->assertOk()->assertExactJson($first->json());
            $this->getJson('/api/v1/pages/home')->assertOk()->assertJsonPath('data.title', $page->title);
        }
        $restored = app(CmsContentService::class)->page('home');
        $this->assertTrue($restored->exists);
        $this->assertTrue($restored->relationLoaded('sections'));
        $this->assertInstanceOf(Collection::class, $restored->sections);
        $this->assertSame(PageStatus::Published, $restored->status);
        $this->assertSame(PageSectionType::Hero, $restored->sections->first()->type);
        $this->assertSame($page->id, $restored->sections->first()->page_id);
        $this->assertIsArray($restored->sections->first()->data);
        foreach (['cms:page:home', 'cms:banners:home_hero', 'cms:banners:home_secondary', 'cms:gallery:preview:6'] as $key) {
            $this->assertSafePayload(Cache::get($key));
        }
    }

    public function test_banners_keep_order_and_recheck_schedules_on_repeated_hits(): void
    {
        $first = Banner::factory()->create(['sort_order' => 1, 'ends_at' => now()->addSeconds(5)]);
        $later = Banner::factory()->create(['sort_order' => 2, 'starts_at' => now()->addSeconds(5)]);
        Banner::factory()->create(['is_active' => false]);
        Banner::factory()->create(['ends_at' => now()->subSecond()]);
        Banner::factory()->create(['placement' => BannerPlacement::HomeSecondary]);
        for ($hit = 0; $hit < 3; $hit++) {
            $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->uuid);
        }
        $restored = app(CmsContentService::class)->banners(BannerPlacement::HomeHero)->first();
        $this->assertSame(BannerPlacement::HomeHero, $restored->placement);
        $this->assertInstanceOf(CarbonImmutable::class, $restored->ends_at);
        $this->travel(5)->seconds();
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $later->uuid);
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $later->uuid);
    }

    public function test_gallery_preview_survives_hits_and_expires_at_next_publication(): void
    {
        $current = GalleryItem::factory()->create(['sort_order' => 2]);
        $scheduled = GalleryItem::factory()->create(['sort_order' => 1, 'published_at' => now()->addSeconds(5)]);
        GalleryItem::factory()->create(['is_active' => false]);
        GalleryItem::factory()->create(['published_at' => null]);
        $cms = app(CmsContentService::class);
        for ($hit = 0; $hit < 3; $hit++) {
            $preview = $cms->galleryPreview(1);
            $this->assertInstanceOf(Collection::class, $preview);
            $this->assertSame([$current->uuid], $preview->pluck('uuid')->all());
            $this->assertInstanceOf(CarbonImmutable::class, $preview->first()->published_at);
        }
        $this->assertSafePayload(Cache::get('cms:gallery:preview:1'));
        $this->travel(5)->seconds();
        for ($hit = 0; $hit < 3; $hit++) {
            $this->assertSame([$scheduled->uuid], $cms->galleryPreview(1)->pluck('uuid')->all());
        }
    }

    public function test_page_publication_is_rechecked_on_cache_hits(): void
    {
        $page = Page::where('key', 'about')->firstOrFail();
        $page->update(['status' => PageStatus::Published, 'published_at' => now()->addSeconds(5)]);
        $this->getJson('/api/v1/pages/about')->assertNotFound();
        $this->getJson('/api/v1/pages/about')->assertNotFound();
        $this->assertSafePayload(Cache::get('cms:page:about'));
        $this->travel(5)->seconds();
        $this->getJson('/api/v1/pages/about')->assertOk();
        $this->getJson('/api/v1/pages/about')->assertOk();
        $page->update(['status' => PageStatus::Draft]);
        $this->getJson('/api/v1/pages/about')->assertNotFound();
    }

    public function test_model_changes_invalidate_serialized_fragments(): void
    {
        $page = Page::where('key', 'home')->firstOrFail();
        $banner = Banner::factory()->create();
        $gallery = GalleryItem::factory()->create();
        $this->getJson('/api/v1/home')->assertOk();
        $this->getJson('/api/v1/home')->assertOk();
        $page->sections()->where('key', 'hero')->firstOrFail()->update(['title' => 'Updated hero']);
        $this->assertNull(Cache::get('cms:page:home'));
        $banner->update(['placement' => BannerPlacement::HomeSecondary]);
        $this->assertNull(Cache::get('cms:banners:home_hero'));
        $this->assertNull(Cache::get('cms:banners:home_secondary'));
        $gallery->update(['is_active' => false]);
        $this->assertNull(Cache::get('cms:gallery:preview:6'));
        for ($hit = 0; $hit < 2; $hit++) {
            $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.page.sections.0.title', 'Updated hero')
                ->assertJsonCount(0, 'data.banners')->assertJsonPath('data.secondary_banners.0.id', $banner->uuid)
                ->assertJsonCount(0, 'data.gallery_preview');
        }
        $page->update(['title' => 'Updated page']);
        $this->getJson('/api/v1/pages/home')->assertOk()->assertJsonPath('data.title', 'Updated page');
        $banner->delete();
        $this->getJson('/api/v1/banners?placement=home_secondary')->assertOk()->assertJsonCount(0, 'data');
        $page->delete();
        $this->getJson('/api/v1/home')->assertNotFound();
    }

    public function test_legacy_object_fragments_are_rebuilt_without_flushing_other_cache(): void
    {
        $page = Page::where('key', 'home')->with('sections')->firstOrFail();
        Banner::factory()->create();
        Banner::factory()->create(['placement' => BannerPlacement::HomeSecondary]);
        GalleryItem::factory()->create();
        $legacy = [
            'cms:page:home' => $page,
            'cms:banners:home_hero' => Banner::where('placement', 'home_hero')->get(),
            'cms:banners:home_secondary' => Banner::where('placement', 'home_secondary')->get(),
            'cms:gallery:preview:6' => GalleryItem::all(),
        ];
        foreach ($legacy as $key => $value) {
            Cache::put($key, $value, 300);
            $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get($key));
        }
        Cache::put('unrelated', ['keep' => true], 300);
        for ($hit = 0; $hit < 3; $hit++) {
            $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(1, 'data.banners')
                ->assertJsonCount(1, 'data.secondary_banners')->assertJsonCount(1, 'data.gallery_preview');
        }
        foreach (array_keys($legacy) as $key) {
            $this->assertSafePayload(Cache::get($key));
        }
        $this->assertSame(['keep' => true], Cache::get('unrelated'));
    }

    private function assertSafePayload(mixed $value): void
    {
        $this->assertIsArray($value);
        array_walk_recursive($value, function (mixed $item): void {
            $this->assertTrue($item === null || is_scalar($item), 'CMS cache must contain only arrays, scalars and null.');
        });
    }
}
