<?php

namespace Tests\Feature;

use App\Enums\PageSectionType;
use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\User;
use Database\Seeders\CmsPageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CmsPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        Sanctum::actingAs($user);

        return $user;
    }

    private function page(string $key = 'about'): Page
    {
        return Page::factory()->create(['key' => $key, 'slug' => $key]);
    }

    public function test_published_page_returns_ordered_active_sections_and_resolved_seo_without_internal_fields(): void
    {
        $page = $this->page();
        $second = PageSection::factory()->for($page)->create(['key' => 'quality', 'type' => PageSectionType::Text, 'sort_order' => 20]);
        $first = PageSection::factory()->for($page)->create(['key' => 'story', 'type' => PageSectionType::ImageText, 'sort_order' => 1]);
        PageSection::factory()->for($page)->create(['key' => 'hero', 'is_active' => false]);
        $response = $this->getJson('/api/v1/pages/about')->assertOk()->assertJsonPath('data.key', 'about')->assertJsonPath('data.sections.0.id', $first->uuid)->assertJsonPath('data.sections.1.id', $second->uuid)->assertJsonCount(2, 'data.sections')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.sections.0.page_id');
        $this->assertStringContainsString($page->title, $response->json('data.seo.title'));
        $this->assertTrue(Cache::has('cms:page:about'));
    }

    #[DataProvider('hiddenPages')]
    public function test_public_hides_drafts_and_future_publication(array $state): void
    {
        $this->page()->update($state);
        $this->getJson('/api/v1/pages/about')->assertNotFound();
    }

    public static function hiddenPages(): array
    {
        return ['draft' => [['status' => PageStatus::Draft]], 'future' => [['published_at' => '2099-01-01 00:00:00']]];
    }

    public function test_page_schedule_is_rechecked_when_fragment_is_warm(): void
    {
        $this->travelTo(now()->startOfSecond());
        $page = $this->page();
        $page->update(['published_at' => now()->addSeconds(10)]);
        $this->getJson('/api/v1/pages/about')->assertNotFound();
        $this->travel(11)->seconds();
        $this->getJson('/api/v1/pages/about')->assertOk();
    }

    public function test_admin_can_view_draft_update_seo_and_actor_without_changing_key(): void
    {
        $actor = $this->admin();
        $page = $this->page();
        $page->update(['status' => PageStatus::Draft]);
        $this->getJson('/api/v1/admin/pages/'.$page->uuid)->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid, ['title' => 'من نحن', 'meta_title' => 'شيشلي', 'canonical_url' => 'https://example.test/about', 'status' => 'published', 'published_at' => null])->assertOk()->assertJsonPath('data.seo.title', 'شيشلي');
        $this->assertSame($actor->id, $page->fresh()->updated_by);
        $this->assertSame('about', $page->fresh()->key);
    }

    public function test_section_update_invalidates_page_cache_and_records_page_editor(): void
    {
        $actor = $this->admin();
        $page = $this->page();
        $section = PageSection::factory()->for($page)->create(['key' => 'hero']);
        $this->getJson('/api/v1/pages/about')->assertOk();
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, ['title' => 'عنوان جديد', 'data' => ['primary_button_text' => 'القائمة', 'primary_button_url' => '/menu.html']])->assertOk();
        $this->getJson('/api/v1/pages/about')->assertOk()->assertJsonPath('data.sections.0.title', 'عنوان جديد');
        $this->assertSame($actor->id, $page->fresh()->updated_by);
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, ['is_active' => false])->assertOk();
        $this->getJson('/api/v1/pages/about')->assertJsonCount(0, 'data.sections');
    }

    public function test_changing_published_page_to_draft_hides_warm_cached_page(): void
    {
        $this->admin();
        $page = $this->page();
        $this->getJson('/api/v1/pages/about')->assertOk();
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid, ['status' => 'draft'])->assertOk();
        $this->getJson('/api/v1/pages/about')->assertNotFound();
    }

    public function test_cross_page_section_binding_is_rejected(): void
    {
        $this->admin();
        $page = $this->page();
        $other = $this->page('home');
        $section = PageSection::factory()->for($other)->create();
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, ['title' => 'Wrong'])->assertNotFound();
    }

    public function test_seeder_is_idempotent_and_preserves_editor_content(): void
    {
        $this->seed(CmsPageSeeder::class);
        $page = Page::where('key', 'about')->firstOrFail();
        $page->update(['title' => 'Edited', 'status' => PageStatus::Published]);
        $page->sections()->where('key', 'story')->update(['content' => 'Edited story']);
        $this->seed(CmsPageSeeder::class);
        $this->assertDatabaseCount('pages', 4);
        $this->assertDatabaseCount('page_sections', 19);
        $this->assertSame('Edited', $page->fresh()->title);
        $this->assertDatabaseHas('page_sections', ['page_id' => $page->id, 'key' => 'story', 'content' => 'Edited story']);
    }

    public function test_og_image_upload_uses_configured_disk_and_hides_path(): void
    {
        Storage::fake('public');
        $this->admin();
        $page = $this->page();
        $this->patch('/api/v1/admin/pages/'.$page->uuid, ['og_image' => UploadedFile::fake()->image('evil-name.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.og_image');
        Storage::disk('public')->assertExists($page->fresh()->og_image);
        $this->getJson('/api/v1/pages/about')->assertJsonPath('data.seo.og_image_url', Storage::disk('public')->url($page->fresh()->og_image));
    }

    public function test_unknown_public_page_is_not_exposed_even_if_a_row_exists(): void
    {
        $this->page('custom');
        $this->getJson('/api/v1/pages/custom')->assertNotFound();
    }

    public function test_section_media_upload_preserves_image_on_text_update_and_supports_safe_clear(): void
    {
        Storage::fake('public');
        $this->admin();
        $page = $this->page();
        $section = PageSection::factory()->for($page)->create(['key' => 'story', 'type' => PageSectionType::ImageText]);
        $url = '/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid;
        $response = $this->patch($url, ['image' => UploadedFile::fake()->image('story.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.data.image_path');
        $old = $section->fresh()->data['image_path'];
        Storage::disk('public')->assertExists($old);
        $response->assertJsonPath('data.data.image_url', Storage::disk('public')->url($old));
        $this->patchJson($url, ['data' => ['primary_button_text' => 'Menu', 'primary_button_url' => '/menu.html']])->assertOk()->assertJsonPath('data.data.image_url', Storage::disk('public')->url($old));
        $this->patchJson($url, ['image' => null])->assertOk()->assertJsonMissingPath('data.data.image_url');
        Storage::disk('public')->assertMissing($old);
    }

    public function test_deleted_page_is_hidden_after_fragment_invalidation(): void
    {
        $page = $this->page();
        $this->getJson('/api/v1/pages/about')->assertOk();
        $page->delete();
        $this->getJson('/api/v1/pages/about')->assertNotFound();
    }
}
