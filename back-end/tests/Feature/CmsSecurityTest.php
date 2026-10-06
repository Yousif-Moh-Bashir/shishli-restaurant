<?php

namespace Tests\Feature;

use App\Enums\PageSectionType;
use App\Models\Banner;
use App\Models\GalleryItem;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CmsSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        Sanctum::actingAs($user);
    }

    #[DataProvider('adminEndpoints')]
    public function test_admin_endpoints_require_authentication_and_permission(string $verb, string $url): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $page = Page::factory()->create(['uuid' => $uuid, 'key' => 'home', 'slug' => 'home']);
        PageSection::factory()->for($page)->create(['uuid' => $uuid]);
        Banner::factory()->create(['uuid' => $uuid]);
        GalleryItem::factory()->create(['uuid' => $uuid]);
        $this->{$verb}($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->{$verb}($url)->assertForbidden();
    }

    public static function adminEndpoints(): array
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $rows = [];
        foreach ([['getJson', '/pages'], ['getJson', '/pages/'.$uuid], ['patchJson', '/pages/'.$uuid], ['getJson', '/pages/'.$uuid.'/sections'], ['patchJson', '/pages/'.$uuid.'/sections/'.$uuid]] as [$v,$p]) {
            $rows[$v.$p] = [$v, '/api/v1/admin'.$p];
        }
        foreach (['banners', 'gallery'] as $path) {
            foreach ([['getJson', ''], ['postJson', ''], ['getJson', '/'.$uuid], ['patchJson', '/'.$uuid], ['deleteJson', '/'.$uuid], ['patchJson', '/reorder']] as [$v,$p]) {
                $rows[$v.$path.$p] = [$v, '/api/v1/admin/'.$path.$p];
            }
        }

        return $rows;
    }

    #[DataProvider('badUrls')]
    public function test_dangerous_banner_links_are_rejected(string $url): void
    {
        $this->admin();
        $banner = Banner::factory()->create();
        $this->patchJson('/api/v1/admin/banners/'.$banner->uuid, ['button_url' => $url])->assertUnprocessable()->assertJsonValidationErrors('button_url');
    }

    public static function badUrls(): array
    {
        return array_map(fn ($url) => [$url], ['javascript:alert(1)', 'data:text/html,test', '//evil.test', '/\\evil.test', '/%2f%2fevil.test', '/%252f%252fevil.test', '/../secret', 'https://user:pass@example.test', 'ftp://example.test', 'https://example.test/<script>']);
    }

    #[DataProvider('safeUrls')]
    public function test_safe_internal_and_http_links_are_accepted(string $url): void
    {
        $this->admin();
        $banner = Banner::factory()->create();
        $this->patchJson('/api/v1/admin/banners/'.$banner->uuid, ['button_url' => $url])->assertOk();
    }

    public static function safeUrls(): array
    {
        return [['/menu.html'], ['/menu.html?category=chicken'], ['https://example.test/menu'], ['http://example.test/menu']];
    }

    #[DataProvider('badPageFields')]
    public function test_page_rejects_immutable_unknown_or_invalid_fields(array $data): void
    {
        $this->admin();
        $page = Page::factory()->create(['key' => 'about', 'slug' => 'about']);
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid, $data)->assertUnprocessable();
    }

    public static function badPageFields(): array
    {
        return [
            'key' => [['key' => 'home']], 'uuid' => [['uuid' => '123']], 'actor' => [['updated_by' => 1]], 'status' => [['status' => 'approved']],
            'seo length' => [['meta_title' => str_repeat('x', 101)]], 'description length' => [['meta_description' => str_repeat('x', 321)]],
            'canonical' => [['canonical_url' => '/menu.html']], 'markup' => [['title' => '<script>alert(1)</script>']],
            'encoded markup' => [['meta_description' => '&lt;img src=x onerror=alert(1)&gt;']], 'media path' => [['og_image' => '../secret.jpg']],
        ];
    }

    #[DataProvider('badSectionData')]
    public function test_section_rejects_unstructured_or_unsafe_content(array $data): void
    {
        $this->admin();
        $page = Page::factory()->create(['key' => 'home', 'slug' => 'home']);
        $section = PageSection::factory()->for($page)->create();
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, $data)->assertUnprocessable();
    }

    public static function badSectionData(): array
    {
        return [
            [['key' => 'typo']], [['type' => 'custom_class']], [['content' => '<iframe src="https://evil.test"></iframe>']],
            [['data' => ['component' => 'arbitrary']]], [['data' => ['primary_button_url' => 'javascript:alert(1)']]],
            [['data' => ['primary_button_text' => '<img src=x onerror=alert(1)>']]], [['data' => ['primary_button_text' => str_repeat('x', 101)]]],
            [['data' => 'raw json']], [['sort_order' => -1]], [['is_active' => 'anything']],
        ];
    }

    public function test_unknown_persisted_section_cannot_be_edited_or_exposed(): void
    {
        $this->admin();
        $page = Page::factory()->create(['key' => 'home', 'slug' => 'home']);
        $section = PageSection::factory()->for($page)->create(['key' => 'typo']);
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, ['title' => 'bad'])->assertUnprocessable();
        $this->getJson('/api/v1/pages/home')->assertJsonCount(0, 'data.sections');
    }

    public function test_arbitrary_page_creation_is_unavailable(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/pages', ['key' => 'new'])->assertMethodNotAllowed();
    }

    public function test_contact_operational_values_cannot_be_duplicated_in_section_data(): void
    {
        $this->admin();
        $page = Page::factory()->create(['key' => 'contact', 'slug' => 'contact']);
        $section = PageSection::factory()->for($page)->create(['key' => 'location', 'type' => PageSectionType::Contact]);
        $this->patchJson('/api/v1/admin/pages/'.$page->uuid.'/sections/'.$section->uuid, ['data' => ['phone' => '123', 'hours' => '24/7']])->assertUnprocessable();
    }
}
