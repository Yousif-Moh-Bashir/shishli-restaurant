<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tree_contains_only_active_roots_and_sorted_active_children(): void
    {
        $last = Category::factory()->create(['name' => 'Z', 'sort_order' => 1]);
        $first = Category::factory()->create(['name' => 'A', 'sort_order' => 1]);
        $childLast = Category::factory()->childOf($first)->create(['name' => 'Z', 'sort_order' => 0]);
        $childFirst = Category::factory()->childOf($first)->create(['name' => 'A', 'sort_order' => 0]);
        Category::factory()->childOf($first)->inactive()->create();
        Category::factory()->inactive()->create();
        $deleted = Category::factory()->childOf($first)->create();
        $deleted->delete();

        $response = $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->uuid)->assertJsonPath('data.1.id', $last->uuid)
            ->assertJsonCount(2, 'data.0.children')->assertJsonPath('data.0.children.0.id', $childFirst->uuid)
            ->assertJsonPath('data.0.children.1.id', $childLast->uuid);
        $this->assertArrayNotHasKey('parent_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('deleted_at', $response->json('data.0'));
    }

    public function test_public_show_uses_uuid_and_does_not_expose_inactive_relations(): void
    {
        $parent = Category::factory()->inactive()->create();
        $category = Category::factory()->childOf($parent)->create();
        Category::factory()->childOf($category)->inactive()->create();

        $this->getJson('/api/v1/categories/'.$category->uuid)->assertOk()
            ->assertJsonPath('data.id', $category->uuid)->assertJsonPath('data.parent', null)
            ->assertJsonCount(0, 'data.children');
        $this->getJson('/api/v1/categories/'.$parent->uuid)->assertNotFound();
        $this->getJson('/api/v1/categories/'.$category->id)->assertNotFound();
        $this->getJson('/api/v1/categories/'.Str::uuid())->assertNotFound();
    }

    #[DataProvider('adminEndpoints')]
    public function test_admin_endpoints_require_authentication_and_the_specific_permission(string $method, string $suffix, string $permission): void
    {
        $category = Category::factory()->create();
        $url = '/api/v1/admin/categories'.($suffix === '' ? '' : '/'.$category->uuid);
        $payload = ['name' => 'Authorized'];
        $this->json($method, $url, $payload)->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $this->withToken($user->createToken('categories')->plainTextToken);
        $this->json($method, $url, $payload)->assertForbidden();
        $user->givePermissionTo($permission);
        app('auth')->forgetGuards();
        $this->json($method, $url, $payload)->assertStatus($method === 'POST' ? 201 : 200);

        if ($method === 'DELETE') {
            $this->assertSoftDeleted($category);
        } elseif (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $this->assertDatabaseHas('categories', ['name' => 'Authorized']);
        }
    }

    public static function adminEndpoints(): array
    {
        return [
            'list' => ['GET', '', 'categories.view'],
            'show' => ['GET', '/category', 'categories.view'],
            'create' => ['POST', '', 'categories.create'],
            'patch' => ['PATCH', '/category', 'categories.update'],
            'put' => ['PUT', '/category', 'categories.update'],
            'delete' => ['DELETE', '/category', 'categories.delete'],
        ];
    }

    public function test_view_permission_does_not_grant_write_permissions(): void
    {
        $this->authorizeCategory('categories.view');
        $category = Category::factory()->create();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Forbidden'])->assertForbidden();
        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, ['name' => 'Forbidden'])->assertForbidden();
        $this->deleteJson('/api/v1/admin/categories/'.$category->uuid)->assertForbidden();
        $this->assertNotSame('Forbidden', $category->fresh()->name);
        $this->assertNotSoftDeleted($category);
    }

    public function test_admin_list_paginates_active_and_inactive_categories_with_public_parent_identifiers(): void
    {
        $this->authorizeCategory('categories.view');
        $parent = Category::factory()->create(['sort_order' => 1]);
        $child = Category::factory()->childOf($parent)->inactive()->create(['sort_order' => 0]);
        Category::factory()->count(19)->create(['sort_order' => 2]);

        $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.id', $child->uuid)->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.parent.id', $parent->uuid);
        $this->getJson('/api/v1/admin/categories?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_create_generates_unique_slugs_including_deleted_records_and_arabic_names(): void
    {
        $this->authorizeCategory('categories.create');
        $existing = Category::factory()->create(['slug' => 'grills']);
        $existing->delete();
        Category::factory()->create(['slug' => 'grills-2']);
        $this->postJson('/api/v1/admin/categories', ['name' => 'Grills'])
            ->assertCreated()->assertJsonPath('data.slug', 'grills-3');
        $first = $this->postJson('/api/v1/admin/categories', ['name' => 'الدجاج التركي'])->assertCreated();
        $second = $this->postJson('/api/v1/admin/categories', ['name' => 'الدجاج التركي'])->assertCreated();
        $this->assertNotEmpty($first->json('data.slug'));
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
        $this->assertTrue(Str::isUuid($first->json('data.id')));
    }

    public function test_empty_transliteration_falls_back_and_checks_collisions(): void
    {
        $this->authorizeCategory('categories.create');
        Str::createRandomStringsUsing(fn (): string => 'aaaaaaaa');
        try {
            $this->postJson('/api/v1/admin/categories', ['name' => '🍗'])->assertCreated()->assertJsonPath('data.slug', 'category-aaaaaaaa');
            $this->postJson('/api/v1/admin/categories', ['name' => '🍗'])->assertCreated()->assertJsonPath('data.slug', 'category-aaaaaaaa-2');
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_deleted_slugs_stay_reserved_on_create_and_update(): void
    {
        $this->authorizeCategory('categories.create', 'categories.update');
        $deleted = Category::factory()->create();
        $deleted->delete();
        $other = Category::factory()->create();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Duplicate', 'slug' => $deleted->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson('/api/v1/admin/categories/'.$other->uuid, ['slug' => $deleted->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->assertSame($other->slug, $other->fresh()->slug);
    }

    public function test_parent_can_change_be_preserved_and_be_cleared_without_accepting_internal_fields(): void
    {
        $this->authorizeCategory('categories.update');
        $parent = Category::factory()->create();
        $other = Category::factory()->create();
        $category = Category::factory()->childOf($parent)->create();
        $url = '/api/v1/admin/categories/'.$category->uuid;
        $this->patchJson($url, ['parent_uuid' => $other->uuid])->assertOk();
        $this->assertSame($other->id, $category->fresh()->parent_id);
        $this->patchJson($url, [
            'name' => 'Updated', 'sort_order' => 4, 'parent_id' => $parent->id,
            'uuid' => (string) Str::uuid(), 'id' => 9000, 'deleted_at' => now()->toISOString(),
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
        ])->assertOk()->assertJsonPath('data.id', $category->uuid);
        $fresh = $category->fresh();
        $this->assertSame($other->id, $fresh->parent_id);
        $this->assertSame('Updated', $fresh->name);
        $this->assertSame(4, $fresh->sort_order);
        $this->assertSame($category->created_at->toISOString(), $fresh->created_at->toISOString());
        $this->assertNotSoftDeleted($category);
        $this->patchJson($url, ['parent_uuid' => null])->assertOk();
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_self_parent_and_deep_circular_hierarchies_return_422_without_partial_updates(): void
    {
        $this->authorizeCategory('categories.update');
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $grandchild = Category::factory()->childOf($child)->create();
        foreach ([$root, $grandchild] as $parent) {
            $this->patchJson('/api/v1/admin/categories/'.$root->uuid, ['parent_uuid' => $parent->uuid, 'name' => 'Invalid'])
                ->assertUnprocessable()->assertJsonPath('errors.parent_uuid.0', 'لا يمكن ربط القسم بنفسه أو بأحد أقسامه الفرعية.');
            $this->assertNull($root->fresh()->parent_id);
            $this->assertSame($root->name, $root->fresh()->name);
        }
    }

    public function test_update_rejects_missing_malformed_and_deleted_parents(): void
    {
        $this->authorizeCategory('categories.update');
        $category = Category::factory()->create();
        $deleted = Category::factory()->create();
        $deleted->delete();
        foreach (['invalid', (string) Str::uuid(), $deleted->uuid] as $uuid) {
            $this->patchJson('/api/v1/admin/categories/'.$category->uuid, ['parent_uuid' => $uuid])
                ->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        }
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_delete_rejects_even_inactive_children_but_allows_deleted_children(): void
    {
        $this->authorizeCategory('categories.delete');
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->inactive()->create();
        $this->deleteJson('/api/v1/admin/categories/'.$parent->uuid)->assertUnprocessable()
            ->assertJsonPath('errors.category.0', 'لا يمكن حذف قسم يحتوي على أقسام فرعية.');
        $this->assertNotSoftDeleted($parent);
        $child->delete();
        $this->deleteJson('/api/v1/admin/categories/'.$parent->uuid)->assertOk();
        $this->assertSoftDeleted($parent);
        $this->getJson('/api/v1/categories/'.$parent->uuid)->assertNotFound();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_seeded_menu_has_eleven_ordered_roots_and_does_not_duplicate_deleted_rows(): void
    {
        $this->seed(CategorySeeder::class);
        $this->assertSame([
            'turkish-chicken', 'shish', 'grills', 'mixed-grills', 'meat', 'sandwiches',
            'appetizers', 'stews', 'rice', 'drinks', 'extras',
        ], Category::orderBy('sort_order')->pluck('slug')->all());
        $this->assertSame(11, Category::active()->whereNull('parent_id')->count());
        Category::first()->delete();
        $this->seed(CategorySeeder::class);
        $this->assertDatabaseCount('categories', 11);
        $this->assertSame(10, Category::count());
    }

    public function test_public_query_count_does_not_grow_per_category(): void
    {
        Category::factory()->has(Category::factory()->count(2), 'children')->create();
        DB::enableQueryLog();
        $this->getJson('/api/v1/categories')->assertOk();
        $firstCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        Category::factory()->count(8)->has(Category::factory()->count(2), 'children')->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->assertSame($firstCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_show_returns_public_parent_summary_and_admin_inactive_children(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();
        $inactive = Category::factory()->childOf($parent)->inactive()->create();
        $this->getJson('/api/v1/categories/'.$child->uuid)->assertOk()->assertJsonPath('data.parent', [
            'id' => $parent->uuid, 'name' => $parent->name, 'slug' => $parent->slug,
        ]);
        $this->authorizeCategory('categories.view');
        $this->getJson('/api/v1/admin/categories/'.$parent->uuid)->assertOk()
            ->assertJsonCount(2, 'data.children')->assertJsonFragment(['id' => $inactive->uuid]);
    }

    public function test_nullable_slug_regenerates_on_update_without_replacing_uuid(): void
    {
        $this->authorizeCategory('categories.update');
        $category = Category::factory()->create(['name' => 'Grills']);
        Category::factory()->create(['slug' => 'grills']);
        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, ['slug' => null])
            ->assertOk()->assertJsonPath('data.id', $category->uuid)->assertJsonPath('data.slug', 'grills-2');
        $this->assertSame('grills-2', $category->fresh()->slug);
    }

    #[DataProvider('fieldLimits')]
    public function test_create_and_update_validate_database_field_limits_in_arabic(string $field, mixed $value, string $message): void
    {
        $this->authorizeCategory('categories.create', 'categories.update');
        $category = Category::factory()->create();
        $this->postJson('/api/v1/admin/categories', array_replace(['name' => 'Valid'], [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);
        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, [$field => $value])
            ->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('categories', 1);
    }

    public static function fieldLimits(): array
    {
        return [
            'name' => ['name', str_repeat('a', 151), 'اسم القسم يجب ألا يتجاوز 150 حرفًا.'],
            'slug' => ['slug', str_repeat('a', 181), 'الرابط المختصر يجب ألا يتجاوز 180 حرفًا.'],
            'description' => ['description', str_repeat('a', 2001), 'وصف القسم يجب ألا يتجاوز 2000 حرف.'],
            'image' => ['image', str_repeat('a', 256), 'مسار الصورة يجب ألا يتجاوز 255 حرفًا.'],
            'order' => ['sort_order', -1, 'ترتيب القسم لا يمكن أن يكون أقل من صفر.'],
            'active' => ['is_active', 'invalid', 'حالة القسم غير صحيحة.'],
        ];
    }

    private function authorizeCategory(string ...$permissions): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo($permissions);
        $this->withToken($user->createToken('categories')->plainTextToken);
    }

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
