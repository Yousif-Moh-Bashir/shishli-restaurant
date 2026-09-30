<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OptionGroupType;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\OptionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OptionGroupTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('routes')]
    public function test_permissions_are_required(string $method, string $suffix): void
    {
        $group = OptionGroup::factory()->create();
        $url = '/api/v1/admin/option-groups'.str_replace('{group}', $group->uuid, $suffix);
        $this->json($method, $url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->json($method, $url)->assertForbidden();
    }

    public static function routes(): array
    {
        return [['GET', ''], ['POST', ''], ['GET', '/{group}'], ['PUT', '/{group}'], ['PATCH', '/{group}'], ['DELETE', '/{group}']];
    }

    public function test_creation_defaults_uuid_slug_and_protected_fields(): void
    {
        $this->authorizeOptions('options.create');
        $supplied = (string) Str::uuid();
        $first = $this->postJson('/api/v1/admin/option-groups', [
            'name' => 'الحجم', 'type' => 'single', 'is_required' => true,
            'id' => 999, 'uuid' => $supplied, 'deleted_at' => now(),
        ])->assertCreated()->assertJsonPath('data.min_select', 1)->assertJsonPath('data.max_select', 1)
            ->assertJsonPath('data.is_required', true)->assertJsonMissingPath('data.deleted_at');
        $this->assertTrue(Str::isUuid($first->json('data.id')));
        $this->assertNotSame($supplied, $first->json('data.id'));
        $second = $this->postJson('/api/v1/admin/option-groups', ['name' => 'الحجم', 'type' => 'single'])
            ->assertCreated()->assertJsonPath('data.min_select', 0);
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
        $this->postJson('/api/v1/admin/option-groups', ['name' => 'Extras', 'type' => 'multiple'])
            ->assertCreated()->assertJsonPath('data.max_select', null)->assertJsonPath('data.min_select', 0);
        $this->assertSame(OptionGroupType::Single, OptionGroup::first()->type);
    }

    #[DataProvider('invalidGroups')]
    public function test_invalid_group_configuration_is_rejected(array $data, string $field): void
    {
        $this->authorizeOptions('options.create');
        $this->postJson('/api/v1/admin/option-groups', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('option_groups', 0);
    }

    public static function invalidGroups(): array
    {
        $valid = ['name' => 'Size', 'type' => 'single'];

        return [
            [array_diff_key($valid, ['name' => true]), 'name'],
            [array_replace($valid, ['name' => str_repeat('a', 151)]), 'name'],
            [array_replace($valid, ['type' => 'invalid']), 'type'],
            [$valid + ['max_select' => 2], 'max_select'],
            [$valid + ['max_select' => null], 'max_select'],
            [$valid + ['is_required' => true, 'min_select' => 0], 'min_select'],
            [$valid + ['min_select' => 1], 'min_select'],
            [['name' => 'Extras', 'type' => 'multiple', 'min_select' => 3, 'max_select' => 2], 'max_select'],
            [$valid + ['sort_order' => -1], 'sort_order'],
            [$valid + ['slug' => 'bad/slug'], 'slug'],
        ];
    }

    public function test_patch_validates_merged_configuration_and_slug_uniqueness(): void
    {
        $this->authorizeOptions('options.update');
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 5]);
        $url = '/api/v1/admin/option-groups/'.$group->uuid;
        $this->patchJson($url, ['type' => 'single'])->assertUnprocessable()->assertJsonValidationErrors('max_select');
        $this->assertSame(OptionGroupType::Multiple, $group->fresh()->type);
        $this->patchJson($url, ['type' => 'single', 'max_select' => 1, 'slug' => $group->slug, 'name' => 'Updated'])
            ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.type', 'single');
        $other = OptionGroup::factory()->create();
        $other->delete();
        $this->patchJson($url, ['slug' => $other->slug])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson($url, ['is_required' => true])->assertUnprocessable()->assertJsonValidationErrors('min_select');
        $this->patchJson($url, ['is_required' => true, 'min_select' => 1])->assertOk();
    }

    public function test_list_filters_paginates_and_loads_values_only_on_show(): void
    {
        $this->authorizeOptions('options.view');
        $group = OptionGroup::factory()->multiple()->inactive()->create(['name' => 'Special extras']);
        OptionValue::factory()->for($group, 'optionGroup')->create();
        OptionGroup::factory()->count(3)->create();
        $this->getJson('/api/v1/admin/option-groups?search=Special&type=multiple&is_active=0&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $group->uuid)
            ->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.values');
        $this->getJson('/api/v1/admin/option-groups/'.$group->uuid)->assertOk()->assertJsonCount(1, 'data.values');
        $this->getJson('/api/v1/admin/option-groups?per_page=101&type=no&is_active=yes')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'type', 'is_active']);
        $this->getJson('/api/v1/admin/option-groups/'.$group->id)->assertNotFound();
    }

    public function test_delete_is_soft_and_rejects_links_even_to_deleted_products(): void
    {
        $this->authorizeOptions('options.delete');
        $group = OptionGroup::factory()->create();
        $product = Product::factory()->create();
        $product->optionGroups()->attach($group);
        $url = '/api/v1/admin/option-groups/'.$group->uuid;
        $this->deleteJson($url)->assertUnprocessable();
        $product->delete();
        $this->deleteJson($url)->assertUnprocessable();
        $product->optionGroups()->detach($group);
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted($group);
        $this->deleteJson($url)->assertNotFound();
    }

    public function test_group_changes_cannot_invalidate_existing_defaults_or_product_overrides(): void
    {
        $this->authorizeOptions('options.update');
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 3]);
        OptionValue::factory()->count(2)->for($group, 'optionGroup')->default()->create();
        $url = '/api/v1/admin/option-groups/'.$group->uuid;
        $this->patchJson($url, ['max_select' => 1])->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->assertSame(3, $group->fresh()->max_select);
        $product = Product::factory()->create();
        $product->optionGroups()->attach($group, ['min_select_override' => 2, 'max_select_override' => 3]);
        $this->patchJson($url, ['type' => 'single', 'max_select' => 1])->assertUnprocessable();
        $this->assertSame(OptionGroupType::Multiple, $group->fresh()->type);
    }

    public function test_seeder_is_idempotent_and_preserves_existing_owner_edits(): void
    {
        $this->seed(OptionSeeder::class);
        $size = OptionGroup::where('slug', 'size')->firstOrFail();
        $size->update(['name' => 'Custom size']);
        $this->seed(OptionSeeder::class);
        $this->assertDatabaseCount('option_groups', 4);
        $this->assertDatabaseCount('option_values', 12);
        $this->assertDatabaseCount('product_option_groups', 0);
        $this->assertSame('Custom size', $size->fresh()->name);
        $this->assertSame(['0.00', '5.00', '10.00'], $size->values->pluck('price_modifier')->all());
        $this->assertTrue($size->values->first()->is_default);
    }

    private function authorizeOptions(string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->actingAs($user, 'sanctum');
    }
}
