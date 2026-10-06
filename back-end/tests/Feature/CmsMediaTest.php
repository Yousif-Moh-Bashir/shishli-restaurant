<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\GalleryItem;
use App\Models\User;
use App\Services\CmsContentService;
use App\Services\CmsWriteService;
use App\Services\MediaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CmsMediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('manager');
        Sanctum::actingAs($actor);
    }

    private function createBanner(array $data = []): TestResponse
    {
        return $this->post('/api/v1/admin/banners', array_merge(['placement' => 'home_hero', 'image' => UploadedFile::fake()->image('original.jpg')], $data), ['Accept' => 'application/json']);
    }

    public function test_banner_upload_random_filename_mobile_fallback_and_actor(): void
    {
        $response = $this->createBanner()->assertCreated();
        $banner = Banner::where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('public')->assertExists($banner->image);
        $this->assertMatchesRegularExpression('~^cms/banners/'.$banner->uuid.'/[a-f0-9-]{36}\\.jpg$~', $banner->image);
        $this->assertSame(auth()->id(), $banner->created_by);
        $this->assertSame(auth()->id(), $banner->updated_by);
        $response->assertJsonPath('data.mobile_image_url', $response->json('data.image_url'))->assertJsonMissingPath('data.image')->assertJsonMissingPath('data.created_by');
    }

    public function test_desktop_and_mobile_uploads_are_separate(): void
    {
        $response = $this->createBanner(['mobile_image' => UploadedFile::fake()->image('mobile.png')])->assertCreated();
        $banner = Banner::where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('public')->assertExists([$banner->image, $banner->mobile_image]);
        $this->assertNotSame($response->json('data.image_url'), $response->json('data.mobile_image_url'));
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_mime_svg_and_oversized_files_are_rejected(string $kind): void
    {
        $file = match ($kind) {
            'svg' => UploadedFile::fake()->createWithContent('file.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),'pdf' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),'large' => UploadedFile::fake()->image('file.jpg')->size(5121),default => UploadedFile::fake()->createWithContent('file.jpg', 'plain text')
        };
        if ($kind === 'disguised') {
            $file->mimeType('text/plain');
        }
        $this->createBanner(['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function invalidUploads(): array
    {
        return [['svg'], ['pdf'], ['large'], ['disguised']];
    }

    public function test_banner_image_replacement_cleans_old_only_after_success(): void
    {
        $id = $this->createBanner()->assertCreated()->json('data.id');
        $banner = Banner::where('uuid', $id)->firstOrFail();
        $old = $banner->image;
        $this->patch('/api/v1/admin/banners/'.$id, ['image' => UploadedFile::fake()->image('next.jpg')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($banner->fresh()->image);
    }

    public function test_database_failure_removes_uploaded_orphan_and_preserves_original(): void
    {
        $id = $this->createBanner()->assertCreated()->json('data.id');
        $banner = Banner::where('uuid', $id)->firstOrFail();
        $old = $banner->image;
        Event::listen('eloquent.updating: '.Banner::class, function (): void {
            throw new RuntimeException('Forced persistence failure');
        });
        try {
            app(CmsWriteService::class)->save($banner, ['image' => UploadedFile::fake()->image('next.jpg')], auth()->user());
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced persistence failure', $exception->getMessage());
        }
        $this->assertSame([$old], Storage::disk('public')->allFiles());
        $this->assertSame($old, $banner->fresh()->image);
    }

    public function test_failure_of_old_media_cleanup_does_not_rollback_content_update(): void
    {
        $id = $this->createBanner()->assertCreated()->json('data.id');
        $banner = Banner::where('uuid', $id)->firstOrFail();
        $mock = \Mockery::mock(MediaService::class)->makePartial();
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('Cleanup failed'));
        $this->app->instance(MediaService::class, $mock);
        $this->patch('/api/v1/admin/banners/'.$id, ['image' => UploadedFile::fake()->image('next.jpg'), 'title' => 'Saved'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('Saved', $banner->fresh()->title);
    }

    public function test_soft_delete_cleans_files_after_success(): void
    {
        $id = $this->createBanner()->assertCreated()->json('data.id');
        $banner = Banner::where('uuid', $id)->firstOrFail();
        $this->getJson('/api/v1/banners')->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/admin/banners/'.$id)->assertOk();
        $this->assertSoftDeleted($banner);
        Storage::disk('public')->assertMissing($banner->image);
        $this->getJson('/api/v1/banners')->assertJsonCount(0, 'data');
    }

    public function test_rollback_keeps_media_and_metadata(): void
    {
        $id = $this->createBanner()->assertCreated()->json('data.id');
        $banner = Banner::where('uuid', $id)->firstOrFail();
        try {
            DB::transaction(function () use ($banner): void {
                app(CmsWriteService::class)->delete($banner);
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertNotNull($banner->fresh());
        Storage::disk('public')->assertExists($banner->image);
    }

    public function test_banner_schedule_filters_and_inclusive_end_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        $active = Banner::factory()->create(['starts_at' => now(), 'ends_at' => now(), 'sort_order' => 2]);
        $first = Banner::factory()->create(['starts_at' => null, 'ends_at' => null, 'sort_order' => 1]);
        Banner::factory()->create(['is_active' => false]);
        Banner::factory()->create(['starts_at' => now()->addDay()]);
        Banner::factory()->create(['ends_at' => now()->subSecond()]);
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->uuid)->assertJsonPath('data.1.id', $active->uuid);
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/banners')->assertJsonCount(1, 'data');
    }

    public function test_scheduled_banner_appears_from_warm_cache_at_start(): void
    {
        $this->travelTo(now()->startOfSecond());
        Banner::factory()->create(['starts_at' => now()->addSeconds(5)]);
        $this->getJson('/api/v1/banners')->assertJsonCount(0, 'data');
        $this->travel(6)->seconds();
        $this->getJson('/api/v1/banners')->assertJsonCount(1, 'data');
    }

    public function test_partial_banner_dates_validate_against_existing_dates(): void
    {
        $banner = Banner::factory()->create();
        $this->patchJson('/api/v1/admin/banners/'.$banner->uuid, ['ends_at' => now()->subDays(2)->toISOString()])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_offset_dates_are_stored_as_utc_and_publish_at_the_correct_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00 UTC'));
        $banner = Banner::factory()->create();
        $this->patchJson('/api/v1/admin/banners/'.$banner->uuid, [
            'starts_at' => '2026-10-03T12:00:00+03:00',
            'ends_at' => '2026-10-03T13:00:00+03:00',
        ])->assertOk();
        $this->assertSame('2026-10-03 09:00:00', $banner->fresh()->getRawOriginal('starts_at'));
        $this->getJson('/api/v1/banners')->assertJsonCount(1, 'data');
        $item = GalleryItem::factory()->create(['published_at' => null]);
        $this->patchJson('/api/v1/admin/gallery/'.$item->uuid, ['published_at' => '2026-10-03T12:00:00+03:00'])->assertOk();
        $this->getJson('/api/v1/gallery')->assertJsonCount(1, 'data');
    }

    public function test_banner_update_invalidates_old_and_new_placements(): void
    {
        $banner = Banner::factory()->create();
        $this->getJson('/api/v1/banners')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/banners?placement=home_secondary')->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/admin/banners/'.$banner->uuid, ['placement' => 'home_secondary'])->assertOk();
        $this->getJson('/api/v1/banners')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/banners?placement=home_secondary')->assertJsonCount(1, 'data');
    }

    #[DataProvider('reorderDomains')]
    public function test_reorder_changes_public_order_and_rejects_duplicates(string $path, string $class): void
    {
        $first = $class::factory()->create(['sort_order' => 0]);
        $second = $class::factory()->create(['sort_order' => 1]);
        $this->patchJson('/api/v1/admin/'.$path.'/reorder', ['items' => [['id' => $first->uuid, 'sort_order' => 2], ['id' => $second->uuid, 'sort_order' => 0]]])->assertOk();
        $this->getJson('/api/v1/'.$path)->assertJsonPath('data.0.id', $second->uuid);
        $this->patchJson('/api/v1/admin/'.$path.'/reorder', ['items' => [['id' => $first->uuid, 'sort_order' => 0], ['id' => $first->uuid, 'sort_order' => 1]]])->assertUnprocessable();
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public static function reorderDomains(): array
    {
        return [['banners', Banner::class], ['gallery', GalleryItem::class]];
    }

    public function test_gallery_upload_update_delete_and_media_url(): void
    {
        $response = $this->post('/api/v1/admin/gallery', ['image' => UploadedFile::fake()->image('gallery.png'), 'alt_text' => 'صورة', 'published_at' => now()->toISOString()], ['Accept' => 'application/json'])->assertCreated();
        $item = GalleryItem::where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('public')->assertExists($item->image);
        $this->getJson('/api/v1/gallery')->assertJsonPath('data.0.image_url', Storage::disk('public')->url($item->image))->assertJsonMissingPath('data.0.image');
        $this->patchJson('/api/v1/admin/gallery/'.$item->uuid, ['title' => 'Updated'])->assertOk();
        $this->assertSame('Updated', $item->fresh()->title);
        $this->deleteJson('/api/v1/admin/gallery/'.$item->uuid)->assertOk();
        $this->assertSoftDeleted($item);
        Storage::disk('public')->assertMissing($item->image);
    }

    public function test_gallery_visibility_pagination_and_maximum_page_size(): void
    {
        GalleryItem::factory()->count(25)->create();
        GalleryItem::factory()->create(['is_active' => false]);
        GalleryItem::factory()->create(['published_at' => null]);
        GalleryItem::factory()->create(['published_at' => now()->addDay()]);
        $this->getJson('/api/v1/gallery')->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 25);
        $this->getJson('/api/v1/gallery?page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/gallery?per_page=101')->assertUnprocessable();
    }

    public function test_gallery_update_invalidates_cached_preview(): void
    {
        $item = GalleryItem::factory()->create();
        $cms = app(CmsContentService::class);
        $this->assertCount(1, $cms->galleryPreview(6));
        $this->patchJson('/api/v1/admin/gallery/'.$item->uuid, ['is_active' => false])->assertOk();
        $this->assertCount(0, $cms->galleryPreview(6));
    }

    public function test_future_gallery_publication_expires_preview_cache_at_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        GalleryItem::factory()->create(['published_at' => now()->addSeconds(5)]);
        $cms = app(CmsContentService::class);
        $this->assertCount(0, $cms->galleryPreview(6));
        $this->travel(6)->seconds();
        $this->assertCount(1, $cms->galleryPreview(6));
    }

    public function test_gallery_database_failure_cleans_uploaded_file(): void
    {
        Event::listen('eloquent.creating: '.GalleryItem::class, function (): void {
            throw new RuntimeException('Fail');
        });
        try {
            app(CmsWriteService::class)->save(new GalleryItem, ['image' => UploadedFile::fake()->image('test.jpg')], auth()->user());
            $this->fail('Expected exception');
        } catch (RuntimeException) {
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('gallery_items', 0);
    }

    public function test_mobile_image_can_be_cleared_but_desktop_cannot(): void
    {
        $id = $this->createBanner(['mobile_image' => UploadedFile::fake()->image('mobile.jpg')])->json('data.id');
        $this->patchJson('/api/v1/admin/banners/'.$id, ['mobile_image' => null])->assertOk();
        $this->patchJson('/api/v1/admin/banners/'.$id, ['image' => null])->assertUnprocessable();
    }
}
