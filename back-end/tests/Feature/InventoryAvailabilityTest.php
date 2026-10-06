<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Services\InventoryAvailabilityService;
use App\Services\RestaurantSettingsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function cart(Branch $branch): Cart
    {
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'))->withHeader('X-Cart-UUID', $response->json('data.id'));

        return Cart::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    public function test_menu_shows_out_of_stock_with_reason_without_quantities_and_filters_availability(): void
    {
        $inventory = BranchInventory::factory()->outOfStock()->create();
        $inventory->branch->products()->attach($inventory->product);
        $url = '/api/v1/menu?branch='.$inventory->branch->uuid;
        $this->getJson($url)->assertOk()->assertJsonPath('data.categories.0.products.0.is_available', false)
            ->assertJsonPath('data.categories.0.products.0.availability_reason', 'OUT_OF_STOCK')
            ->assertJsonMissingPath('data.categories.0.products.0.on_hand')->assertJsonMissingPath('data.categories.0.products.0.reserved')
            ->assertJsonMissingPath('data.categories.0.products.0.available_quantity');
        $this->getJson($url.'&available=1')->assertOk()->assertJsonCount(0, 'data.categories');
        $this->getJson($url.'&available=0')->assertOk()->assertJsonCount(1, 'data.categories.0.products');
        $this->getJson('/api/v1/menu/products/'.$inventory->product->uuid.'?branch='.$inventory->branch->uuid)->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.availability_reason', 'OUT_OF_STOCK')->assertJsonMissingPath('data.on_hand');
    }

    public function test_product_without_branch_does_not_claim_global_stock_availability(): void
    {
        $product = Product::factory()->create(['tracks_inventory' => true]);
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.available', null)
            ->assertJsonPath('data.is_available', null)->assertJsonPath('data.availability_reason', 'BRANCH_REQUIRED');
    }

    #[DataProvider('stockPolicies')]
    public function test_cart_and_menu_enforce_missing_out_insufficient_and_disabled_stock(string $policy, bool $canAdd, ?string $code): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['tracks_inventory' => $policy !== 'untracked']);
        $branch->products()->attach($product);
        if ($policy !== 'missing' && $policy !== 'untracked') {
            BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $policy === 'insufficient' ? '1.999' : '0.000', 'is_tracking' => $policy !== 'disabled']);
        }
        $this->cart($branch);
        $response = $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2]);
        if ($canAdd) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable()->assertJsonPath('errors.inventory.0.code', $code);
        }
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function stockPolicies(): array
    {
        return [['missing', false, 'INVENTORY_NOT_CONFIGURED'], ['out', false, 'OUT_OF_STOCK'],
            ['insufficient', false, 'INSUFFICIENT_STOCK'], ['disabled', true, null], ['untracked', true, null]];
    }

    public function test_add_update_delete_and_clear_cart_never_reserve_stock(): void
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => '3.000']);
        $inventory->branch->products()->attach($inventory->product);
        $cart = $this->cart($inventory->branch);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $inventory->product->uuid, 'quantity' => 2])->assertCreated();
        $item = $cart->items()->firstOrFail();
        $this->patchJson('/api/v1/cart/items/'.$item->uuid, ['quantity' => 4])->assertUnprocessable()->assertJsonPath('errors.inventory.0.code', 'INSUFFICIENT_STOCK');
        $this->assertSame(2, $item->fresh()->quantity);
        $this->patchJson('/api/v1/cart/items/'.$item->uuid, ['quantity' => 3])->assertOk();
        $this->deleteJson('/api/v1/cart/items/'.$item->uuid)->assertOk();
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $inventory->product->uuid, 'quantity' => 3])->assertCreated();
        $this->deleteJson('/api/v1/cart/items')->assertOk();
        $this->assertSame('0.000', $inventory->fresh()->reserved);
        $this->assertSame('3.000', $inventory->fresh()->on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_quantities_of_same_product_across_option_variants_are_summed(): void
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => '3.000']);
        $product = $inventory->product;
        $inventory->branch->products()->attach($product);
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $one = OptionValue::factory()->for($group, 'optionGroup')->create();
        $two = OptionValue::factory()->for($group, 'optionGroup')->create();
        $cart = $this->cart($inventory->branch);
        $payload = fn (OptionValue $value, int $quantity): array => ['product_uuid' => $product->uuid, 'quantity' => $quantity,
            'options' => [['option_group_uuid' => $group->uuid, 'option_value_uuids' => [$value->uuid]]]];
        $this->postJson('/api/v1/cart/items', $payload($one, 2))->assertCreated();
        $this->postJson('/api/v1/cart/items', $payload($two, 2))->assertUnprocessable();
        $this->postJson('/api/v1/cart/items', $payload($two, 1))->assertCreated();
        $this->assertSame(3, (int) $cart->items()->sum('quantity'));
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_refresh_detects_stock_changed_since_cart_creation_and_blocks_checkout(): void
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => '3.000']);
        $inventory->branch->products()->attach($inventory->product);
        $this->cart($inventory->branch);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $inventory->product->uuid, 'quantity' => 3])->assertCreated();
        $inventory->forceFill(['reserved' => '1.000'])->save();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.items.0.issues.0.code', 'INSUFFICIENT_STOCK');
        $this->postJson('/api/v1/checkout', ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'عميل', 'phone' => '0501234567']])
            ->assertUnprocessable()->assertJsonPath('errors.cart.0.code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_is_branch_scoped_and_bulk_loaded_relations_never_leak_between_branches(): void
    {
        $first = BranchInventory::factory()->outOfStock()->create();
        $second = BranchInventory::factory()->for($first->product)->create();
        foreach ([$first, $second] as $inventory) {
            $inventory->branch->products()->attach($inventory->product);
        }
        $this->getJson('/api/v1/menu?branch='.$first->branch->uuid)->assertOk()->assertJsonPath('data.categories.0.products.0.available', false);
        $this->getJson('/api/v1/menu?branch='.$second->branch->uuid)->assertOk()->assertJsonPath('data.categories.0.products.0.available', true);
        $service = app(InventoryAvailabilityService::class);
        $service->load($first->branch, collect([$first->product]));
        $this->assertNull($service->checkProductAvailability($second->branch, $first->product, 2));
    }

    public function test_inventory_cannot_override_operational_product_or_branch_assignment_flags(): void
    {
        $inventory = BranchInventory::factory()->create();
        $inventory->branch->products()->attach($inventory->product, ['is_available' => false]);
        $this->getJson('/api/v1/menu?branch='.$inventory->branch->uuid)->assertOk()->assertJsonPath('data.categories.0.products.0.available', false);
        $this->cart($inventory->branch);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $inventory->product->uuid, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_tracked_menu_query_count_remains_constant_when_products_increase(): void
    {
        $inventory = BranchInventory::factory()->create();
        $branch = $inventory->branch;
        $branch->products()->attach($inventory->product);
        app(RestaurantSettingsService::class)->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/menu?branch='.$branch->uuid)->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (BranchInventory::factory()->count(8)->for($branch)->create() as $additional) {
            $branch->products()->attach($additional->product);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/menu?branch='.$branch->uuid)->assertOk();
        $this->assertSame($baseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
