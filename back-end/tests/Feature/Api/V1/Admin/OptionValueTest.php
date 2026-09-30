<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OptionValueTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('methods')]
    public function test_nested_value_permissions(string $method): void
    {
        $group = OptionGroup::factory()->create();
        $value = OptionValue::factory()->for($group, 'optionGroup')->create();
        $url = $this->base($group).($method === 'POST' ? '' : '/'.$value->uuid);
        $this->json($method, $url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->json($method, $url)->assertForbidden();
    }

    public static function methods(): array
    {
        return [['POST'], ['PATCH'], ['DELETE']];
    }

    public function test_create_uses_route_parent_uuid_and_exact_decimal_and_scoped_slug(): void
    {
        $this->authorizeOptions('options.create');
        $group = OptionGroup::factory()->create();
        $other = OptionGroup::factory()->create();
        $uuid = (string) Str::uuid();
        $response = $this->postJson($this->base($group), [
            'name' => 'Large', 'slug' => 'large', 'price_modifier' => '10.00',
            'option_group_id' => $other->id, 'uuid' => $uuid, 'deleted_at' => now(), 'id' => 999,
        ])->assertCreated()->assertJsonPath('data.price_modifier', '10.00')
            ->assertJsonMissingPath('data.option_group_id')->assertJsonMissingPath('data.deleted_at');
        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $this->assertNotSame($uuid, $response->json('data.id'));
        $this->assertSame($group->id, OptionValue::first()->option_group_id);
        $this->postJson($this->base($group), ['name' => 'Large', 'slug' => 'large'])->assertUnprocessable();
        $this->postJson($this->base($other), ['name' => 'Large', 'slug' => 'large'])->assertCreated();
        OptionValue::first()->delete();
        $this->postJson($this->base($group), ['name' => 'Large', 'slug' => 'large'])->assertUnprocessable();
    }

    #[DataProvider('invalidValues')]
    public function test_value_validation(array $data, string $field): void
    {
        $this->authorizeOptions('options.create');
        $group = OptionGroup::factory()->create();
        $this->postJson($this->base($group), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('option_values', 0);
    }

    public static function invalidValues(): array
    {
        return [
            [[], 'name'], [['name' => str_repeat('a', 151)], 'name'],
            [['name' => 'X', 'price_modifier' => '-0.01'], 'price_modifier'],
            [['name' => 'X', 'price_modifier' => '1.001'], 'price_modifier'],
            [['name' => 'X', 'price_modifier' => '100000000.00'], 'price_modifier'],
            [['name' => 'X', 'sort_order' => -1], 'sort_order'],
            [['name' => 'X', 'is_default' => 'yes'], 'is_default'],
        ];
    }

    public function test_foreign_parent_and_deleted_binding_are_rejected(): void
    {
        $this->authorizeOptions('options.update', 'options.delete');
        $group = OptionGroup::factory()->create();
        $value = OptionValue::factory()->create();
        $url = $this->base($group).'/'.$value->uuid;
        $this->patchJson($url, ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->assertModelExists($value);
        $url = $this->base($value->optionGroup).'/'.$value->uuid;
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted($value);
        $this->patchJson($url, ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_single_default_switches_atomically_and_inactive_clears_default(): void
    {
        $this->authorizeOptions('options.create', 'options.update');
        $group = OptionGroup::factory()->create();
        $first = OptionValue::factory()->for($group, 'optionGroup')->default()->create();
        $second = $this->postJson($this->base($group), ['name' => 'New default', 'is_default' => true])
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $this->assertFalse($first->fresh()->is_default);
        $this->patchJson($this->base($group).'/'.$first->uuid, ['is_default' => true, 'slug' => $first->slug])
            ->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(OptionValue::where('uuid', $second)->first()->is_default);
        $this->patchJson($this->base($group).'/'.$first->uuid, ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_default', false)->assertJsonPath('data.is_active', false);
    }

    public function test_multiple_defaults_respect_minimum_maximum_and_rollback(): void
    {
        $this->authorizeOptions('options.create', 'options.update');
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 2]);
        OptionValue::factory()->count(2)->for($group, 'optionGroup')->default()->create();
        $this->postJson($this->base($group), ['name' => 'Third', 'is_default' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->assertSame(2, $group->values()->count());
        $group->update(['min_select' => 2]);
        $value = $group->values()->first();
        $this->patchJson($this->base($group).'/'.$value->uuid, ['is_default' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->assertTrue($value->fresh()->is_default);
    }

    public function test_used_required_group_cannot_lose_its_last_active_value(): void
    {
        $this->authorizeOptions('options.update', 'options.delete');
        $group = OptionGroup::factory()->required()->create();
        $value = OptionValue::factory()->for($group, 'optionGroup')->default()->create();
        $product = Product::factory()->create();
        $product->optionGroups()->attach($group);
        $url = $this->base($group).'/'.$value->uuid;
        $this->patchJson($url, ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->assertTrue($value->fresh()->is_active);
        $this->assertTrue($value->fresh()->is_default);
        $this->assertNotSoftDeleted($value);
        $product->optionGroups()->detach($group);
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted($value);
    }

    public function test_product_override_minimum_prevents_disabling_values(): void
    {
        $this->authorizeOptions('options.update');
        $group = OptionGroup::factory()->multiple()->create();
        $values = OptionValue::factory()->count(2)->for($group, 'optionGroup')->create();
        Product::factory()->create()->optionGroups()->attach($group, ['min_select_override' => 2]);
        $this->patchJson($this->base($group).'/'.$values[0]->uuid, ['is_active' => false])->assertUnprocessable();
        $this->assertTrue($values[0]->fresh()->is_active);
    }

    private function base(OptionGroup $group): string
    {
        return '/api/v1/admin/option-groups/'.$group->uuid.'/values';
    }

    private function authorizeOptions(string ...$permissions): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user, 'sanctum');
    }
}
