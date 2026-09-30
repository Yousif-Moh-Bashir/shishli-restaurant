<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductOptionGroupTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('methods')]
    public function test_product_link_permissions(string $method): void
    {
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $url = $this->base($product).(in_array($method, ['PATCH', 'DELETE']) ? '/'.$group->uuid : '');
        $this->json($method, $url)->assertUnauthorized();
        $this->authorizeProducts($method === 'GET' ? 'options.view' : 'products.view');
        $this->json($method, $url)->assertForbidden();
        $this->assertSame(1, $product->optionGroups()->count());
    }

    public static function methods(): array
    {
        return [['GET'], ['POST'], ['PATCH'], ['DELETE']];
    }

    public function test_attach_stores_overrides_sort_and_rejects_duplicates(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->create();
        OptionValue::factory()->for($group, 'optionGroup')->create();
        $payload = [
            'option_group_uuid' => $group->uuid, 'sort_order' => 7,
            'is_required_override' => true, 'min_select_override' => 1,
            'max_select_override' => null, 'product_id' => 999, 'option_group_id' => 999,
        ];
        $this->postJson($this->base($product), $payload)->assertCreated()
            ->assertJsonPath('data.id', $group->uuid)->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.min_select', 1)->assertJsonPath('data.max_select', 1)
            ->assertJsonPath('data.sort_order', 7)->assertJsonPath('data.overrides.max_select_override', null)
            ->assertJsonMissingPath('data.pivot')->assertJsonMissingPath('data.product_id');
        $this->assertDatabaseHas('product_option_groups', [
            'product_id' => $product->id, 'option_group_id' => $group->id,
            'sort_order' => 7, 'is_required_override' => true, 'min_select_override' => 1,
        ]);
        $this->postJson($this->base($product), $payload)->assertUnprocessable()->assertJsonValidationErrors('option_group_uuid');
        $this->assertDatabaseCount('product_option_groups', 1);
        $this->assertFalse($group->fresh()->is_required);
    }

    public function test_missing_deleted_and_inactive_group_policy(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        foreach (['invalid', (string) Str::uuid()] as $uuid) {
            $this->postJson($this->base($product), ['option_group_uuid' => $uuid])->assertUnprocessable();
        }
        $group = OptionGroup::factory()->create();
        $group->delete();
        $this->postJson($this->base($product), ['option_group_uuid' => $group->uuid])->assertUnprocessable();
        $inactive = OptionGroup::factory()->required()->inactive()->create();
        $this->postJson($this->base($product), ['option_group_uuid' => $inactive->uuid])->assertCreated();
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.option_groups', []);
    }

    #[DataProvider('invalidOverrides')]
    public function test_invalid_effective_configuration_does_not_create_link(string $type, array $overrides): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->create(['type' => $type, 'max_select' => $type === 'single' ? 1 : 5]);
        OptionValue::factory()->count(4)->for($group, 'optionGroup')->create();
        $this->postJson($this->base($product), ['option_group_uuid' => $group->uuid] + $overrides)->assertUnprocessable();
        $this->assertDatabaseCount('product_option_groups', 0);
    }

    public static function invalidOverrides(): array
    {
        return [
            ['single', ['max_select_override' => 3]],
            ['single', ['is_required_override' => true]],
            ['multiple', ['min_select_override' => 3, 'max_select_override' => 2]],
            ['multiple', ['is_required_override' => true, 'min_select_override' => 0]],
            ['multiple', ['min_select_override' => -1]],
            ['multiple', ['max_select_override' => 0]],
            ['multiple', ['sort_order' => -1]],
        ];
    }

    public function test_patch_merges_existing_overrides_and_null_restores_group_defaults(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 5]);
        OptionValue::factory()->count(3)->for($group, 'optionGroup')->create();
        $product->optionGroups()->attach($group, ['is_required_override' => true, 'min_select_override' => 2, 'max_select_override' => 3]);
        $url = $this->base($product).'/'.$group->uuid;
        $this->patchJson($url, ['max_select_override' => 1])->assertUnprocessable();
        $this->assertSame(3, (int) $product->optionGroups()->first()->pivot->max_select_override);
        $this->patchJson($url, ['sort_order' => 4, 'max_select_override' => null])
            ->assertOk()->assertJsonPath('data.sort_order', 4)->assertJsonPath('data.max_select', 5)
            ->assertJsonPath('data.min_select', 2)->assertJsonPath('data.is_required', true);
        $this->patchJson($url, ['min_select_override' => null])->assertUnprocessable();
        $this->patchJson($url, ['is_required_override' => null, 'min_select_override' => null])
            ->assertOk()->assertJsonPath('data.min_select', 0)->assertJsonPath('data.is_required', false);
    }

    public function test_detach_preserves_group_values_and_other_products_and_foreign_links_return_404(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $group = OptionGroup::factory()->create();
        $value = OptionValue::factory()->for($group, 'optionGroup')->create();
        $other->optionGroups()->attach($group);
        $url = $this->base($product).'/'.$group->uuid;
        $this->patchJson($url, ['sort_order' => 9])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $product->optionGroups()->attach($group);
        $this->deleteJson($url)->assertOk();
        $this->assertSame(0, $product->optionGroups()->count());
        $this->assertSame(1, $other->optionGroups()->count());
        $this->assertNotSoftDeleted($group);
        $this->assertNotSoftDeleted($value);
    }

    public function test_attach_rejects_impossible_capacity_and_default_count(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->required()->create();
        $this->postJson($this->base($product), ['option_group_uuid' => $group->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('values');
        $multiple = OptionGroup::factory()->multiple()->create(['max_select' => 3]);
        OptionValue::factory()->count(2)->for($multiple, 'optionGroup')->default()->create();
        $this->postJson($this->base($product), ['option_group_uuid' => $multiple->uuid, 'max_select_override' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->assertDatabaseCount('product_option_groups', 0);
    }

    public function test_admin_list_includes_inactive_values_and_overrides_with_view_permission(): void
    {
        $this->authorizeProducts('products.view');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->inactive()->create();
        OptionValue::factory()->inactive()->for($group, 'optionGroup')->create();
        $product->optionGroups()->attach($group);
        $this->getJson($this->base($product))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false)->assertJsonPath('data.0.values.0.is_active', false)
            ->assertJsonPath('data.0.overrides.min_select_override', null);
        $this->getJson('/api/v1/admin/products/'.$product->uuid)->assertOk()->assertJsonCount(1, 'data.option_groups');
    }

    public function test_public_detail_filters_orders_and_returns_effective_configuration_without_internal_ids(): void
    {
        $product = Product::factory()->create();
        $first = OptionGroup::factory()->multiple()->create(['sort_order' => 99, 'max_select' => 5]);
        $last = OptionGroup::factory()->create(['sort_order' => 0]);
        $inactive = OptionGroup::factory()->inactive()->create();
        $deleted = OptionGroup::factory()->create();
        $product->optionGroups()->attach($first, ['sort_order' => 1, 'is_required_override' => true, 'min_select_override' => 1, 'max_select_override' => 3]);
        $product->optionGroups()->attach([
            $last->id => ['sort_order' => 9], $inactive->id => ['sort_order' => 0], $deleted->id => ['sort_order' => 0],
        ]);
        $deleted->delete();
        foreach ([['10.00', 3], ['0.00', 1], ['5.00', 2]] as [$price, $sort]) {
            OptionValue::factory()->for($first, 'optionGroup')->create(['price_modifier' => $price, 'sort_order' => $sort]);
        }
        OptionValue::factory()->inactive()->for($first, 'optionGroup')->create(['sort_order' => 0]);
        $hidden = OptionValue::factory()->for($first, 'optionGroup')->create(['sort_order' => 0]);
        $hidden->delete();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonCount(2, 'data.option_groups')
            ->assertJsonPath('data.option_groups.0.id', $first->uuid)
            ->assertJsonPath('data.option_groups.1.id', $last->uuid)
            ->assertJsonPath('data.option_groups.0.is_required', true)
            ->assertJsonPath('data.option_groups.0.min_select', 1)
            ->assertJsonPath('data.option_groups.0.max_select', 3)
            ->assertJsonCount(3, 'data.option_groups.0.values')
            ->assertJsonMissingPath('data.option_groups.0.overrides')
            ->assertJsonMissingPath('data.option_groups.0.pivot')
            ->assertJsonMissingPath('data.option_groups.0.values.0.option_group_id');
        $this->assertSame(['0.00', '5.00', '10.00'], array_column($response->json('data.option_groups.0.values'), 'price_modifier'));
        $valueQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "option_values"'));
        $this->assertCount(1, $valueQueries);
        DB::flushQueryLog();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonMissingPath('data.0.option_groups');
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'option_groups') || str_contains($query['query'], 'option_values')));
        DB::disableQueryLog();
    }

    public function test_deleted_product_cannot_receive_links(): void
    {
        $this->authorizeProducts('products.update');
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->create();
        $product->delete();
        $this->postJson($this->base($product), ['option_group_uuid' => $group->uuid])->assertNotFound();
        $this->assertDatabaseCount('product_option_groups', 0);
    }

    private function base(Product $product): string
    {
        return '/api/v1/admin/products/'.$product->uuid.'/option-groups';
    }

    private function authorizeProducts(string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $this->actingAs($user, 'sanctum');
    }
}
