<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_menu_contains_only_active_categories_in_stable_sort_order(): void
    {
        $last = Category::factory()->create(['sort_order' => 30]);
        $first = Category::factory()->create(['name' => 'A', 'sort_order' => 10]);
        $second = Category::factory()->create(['name' => 'B', 'sort_order' => 10]);
        Category::factory()->inactive()->create(['sort_order' => 0]);

        $response = $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$first->uuid, $second->uuid, $last->uuid], array_column($response->json('data'), 'uuid'));
        $response->assertJsonPath('data.0.id', $first->uuid);
    }

    public function test_manager_can_create_a_category_with_defaults_and_generated_uuid(): void
    {
        $this->authenticateRole('manager');

        $response = $this->postJson('/api/v1/admin/categories', [
            'name' => 'المشويات',
            'slug' => 'grills',
            'id' => 9000,
            'uuid' => '00000000-0000-4000-8000-000000000001',
        ])->assertCreated();

        $category = Category::where('slug', 'grills')->sole();
        $this->assertTrue(Str::isUuid($category->uuid));
        $this->assertNotSame('00000000-0000-4000-8000-000000000001', $category->uuid);
        $this->assertNotSame(9000, $category->id);
        $response->assertExactJson([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'id' => $category->uuid,
                'uuid' => $category->uuid,
                'name' => 'المشويات',
                'slug' => 'grills',
                'description' => null,
                'image' => null,
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => $category->created_at->toISOString(),
                'updated_at' => $category->updated_at->toISOString(),
            ],
            'errors' => null,
        ]);
    }

    public function test_changing_sort_order_changes_public_menu_order_without_code_changes(): void
    {
        $this->authenticateRole('manager');
        $first = Category::factory()->create(['sort_order' => 10]);
        $second = Category::factory()->create(['sort_order' => 20]);

        $this->patchJson('/api/v1/admin/categories/'.$second->uuid, ['sort_order' => 0])
            ->assertOk()->assertJsonPath('data.sort_order', 0);

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame([$second->uuid, $first->uuid], array_column($this->getJson('/api/v1/categories')->json('data'), 'uuid'));
    }

    public function test_update_can_keep_own_slug_clear_optional_fields_and_hide_category(): void
    {
        $this->authenticateRole('manager');
        $category = Category::factory()->create(['description' => 'Description', 'image' => 'categories/grills.jpg']);

        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, [
            'slug' => $category->slug,
            'description' => null,
            'image' => null,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.uuid', $category->uuid)->assertJsonPath('data.is_active', false);

        $category->refresh();
        $this->assertNull($category->description);
        $this->assertNull($category->image);
        $this->assertFalse($category->is_active);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_list_inactive_categories_and_fetch_by_uuid_but_not_internal_id(): void
    {
        $this->authenticateRole('manager');
        $category = Category::factory()->inactive()->create();

        $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/categories/'.$category->uuid)->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/admin/categories/'.$category->id)->assertNotFound();
    }

    public function test_manager_can_delete_category(): void
    {
        $this->authenticateRole('manager');
        $category = Category::factory()->create();

        $this->deleteJson('/api/v1/admin/categories/'.$category->uuid)->assertOk();

        $this->assertSoftDeleted($category);
        $this->getJson('/api/v1/admin/categories/'.$category->uuid)->assertNotFound();
    }

    public function test_duplicate_slug_is_rejected_for_creation_and_update(): void
    {
        $this->authenticateRole('manager');
        $existing = Category::factory()->create(['slug' => 'grills']);
        $other = Category::factory()->create(['slug' => 'rice']);

        $this->postJson('/api/v1/admin/categories', ['name' => 'Duplicate', 'slug' => 'grills'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->patchJson('/api/v1/admin/categories/'.$other->uuid, ['slug' => $existing->slug])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseCount('categories', 2);
        $this->assertSame('rice', $other->fresh()->slug);
    }

    public function test_negative_sort_order_is_rejected_on_update_without_changing_order(): void
    {
        $this->authenticateRole('manager');
        $category = Category::factory()->create(['sort_order' => 10]);

        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, ['sort_order' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['sort_order']);

        $this->assertSame(10, $category->fresh()->sort_order);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_category_data_returns_422_without_creating_records(array $overrides, string $field): void
    {
        $this->authenticateRole('manager');

        $this->postJson('/api/v1/admin/categories', array_replace(['name' => 'Grills', 'slug' => 'grills'], $overrides))
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('categories', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing name' => [['name' => null], 'name'],
            'invalid name' => [['name' => []], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'invalid slug type' => [['slug' => []], 'slug'],
            'invalid slug' => [['slug' => 'invalid slug'], 'slug'],
            'long slug' => [['slug' => str_repeat('a', 256)], 'slug'],
            'description type' => [['description' => []], 'description'],
            'long description' => [['description' => str_repeat('a', 5001)], 'description'],
            'image type' => [['image' => []], 'image'],
            'long image' => [['image' => str_repeat('a', 2049)], 'image'],
            'negative order' => [['sort_order' => -1], 'sort_order'],
            'fractional order' => [['sort_order' => 1.5], 'sort_order'],
            'overflow order' => [['sort_order' => 2147483648], 'sort_order'],
            'invalid flag' => [['is_active' => 'invalid'], 'is_active'],
        ];
    }

    #[DataProvider('roles')]
    public function test_category_creation_requires_manage_permission(string $role, int $status, int $count): void
    {
        $this->authenticateRole($role);

        $this->postJson('/api/v1/admin/categories', ['name' => 'Grills', 'slug' => 'grills'])->assertStatus($status);

        $this->assertDatabaseCount('categories', $count);
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => ['super_admin', 201, 1],
            'manager' => ['manager', 201, 1],
            'cashier' => ['cashier', 403, 0],
            'kitchen' => ['kitchen', 403, 0],
            'customer' => ['customer', 403, 0],
        ];
    }

    public function test_guests_cannot_manage_categories(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Grills', 'slug' => 'grills'])->assertUnauthorized();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_customer_cannot_update_delete_or_read_inactive_admin_category(): void
    {
        $this->authenticateRole('customer');
        $category = Category::factory()->inactive()->create(['sort_order' => 10]);

        $this->getJson('/api/v1/admin/categories/'.$category->uuid)->assertForbidden();
        $this->patchJson('/api/v1/admin/categories/'.$category->uuid, ['sort_order' => 0])->assertForbidden();
        $this->deleteJson('/api/v1/admin/categories/'.$category->uuid)->assertForbidden();

        $this->assertModelExists($category);
        $this->assertSame(10, $category->fresh()->sort_order);
    }

    public function test_menu_seeder_is_repeatable_and_preserves_owner_sort_order(): void
    {
        $this->seed(CategorySeeder::class);
        $category = Category::where('slug', 'grills')->sole();
        $uuid = $category->uuid;
        $category->update(['sort_order' => 999]);

        $this->seed(CategorySeeder::class);

        $this->assertDatabaseCount('categories', 11);
        $this->assertSame('المشويات', $category->name);
        $this->assertSame(999, $category->fresh()->sort_order);
        $this->assertSame($uuid, $category->fresh()->uuid);
    }

    public function test_category_creation_resolves_parent_uuid_instead_of_client_parent_id(): void
    {
        $this->authenticateRole('manager');
        $parent = Category::factory()->create();
        $other = Category::factory()->create();

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Child',
            'slug' => 'child',
            'parent_uuid' => $parent->uuid,
            'parent_id' => $other->id,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'child', 'parent_id' => $parent->id]);
    }

    public function test_category_creation_accepts_null_parent_uuid(): void
    {
        $this->authenticateRole('manager');

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Root', 'slug' => 'root', 'parent_uuid' => null,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'root', 'parent_id' => null]);
    }

    #[DataProvider('invalidParentUuids')]
    public function test_category_creation_rejects_invalid_parent_uuid_with_422(string $parentUuid, string $message): void
    {
        $this->authenticateRole('manager');

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Child', 'slug' => 'child', 'parent_uuid' => $parentUuid,
        ])->assertUnprocessable()->assertJsonPath('errors.parent_uuid.0', $message);

        $this->assertDatabaseCount('categories', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidParentUuids(): array
    {
        return [
            'malformed' => ['invalid', 'معرف القسم الرئيسي غير صحيح.'],
            'missing' => ['00000000-0000-4000-8000-000000000001', 'القسم الرئيسي المحدد غير موجود.'],
        ];
    }

    public function test_category_creation_rejects_deleted_parent_uuid_with_422(): void
    {
        $this->authenticateRole('manager');
        $parent = Category::factory()->create();
        $parent->delete();

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Child', 'slug' => 'child', 'parent_uuid' => $parent->uuid,
        ])->assertUnprocessable()->assertJsonPath('errors.parent_uuid.0', 'القسم الرئيسي المحدد غير موجود.');

        $this->assertDatabaseCount('categories', 1);
    }

    private function authenticateRole(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole($role);
        $this->withToken($user->createToken('category-tests')->plainTextToken);
    }
}
