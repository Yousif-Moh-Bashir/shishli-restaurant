<?php

namespace Tests\Feature;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ReceiveStockAction;
use App\Enums\StockMovementType;
use App\Events\LowStockDetected;
use App\Events\StockRecovered;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockQuantity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(string $role = 'manager'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_opening_balance_is_once_per_branch_product_and_preserves_ledger(): void
    {
        $actor = $this->admin();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['tracks_inventory' => true]);
        $payload = ['branch_uuid' => $branch->uuid, 'product_uuid' => $product->uuid, 'opening_balance' => '12.345', 'low_stock_threshold' => '3.000'];
        $response = $this->postJson('/api/v1/admin/inventory', $payload)->assertCreated()
            ->assertJsonPath('data.stock.on_hand', '12.345')->assertJsonPath('data.stock.reserved', '0.000')->assertJsonPath('data.stock.available', '12.345');
        $movement = StockMovement::firstOrFail();
        $this->assertSame(StockMovementType::OpeningBalance, $movement->type);
        $this->assertSame('0.000', $movement->on_hand_before);
        $this->assertSame('12.345', $movement->on_hand_after);
        $this->assertSame($actor->id, $movement->created_by);
        $this->postJson('/api/v1/admin/inventory', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('branch_inventories', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->getJson('/api/v1/admin/inventory/'.$response->json('data.uuid'))->assertOk();
        $this->assertSame($product->id, $branch->inventories()->first()->product_id);
        $this->assertSame($branch->id, $product->inventories()->first()->branch_id);
    }

    public function test_zero_opening_creates_no_fabricated_movement_and_untracked_product_is_rejected(): void
    {
        $this->admin();
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $payload = ['branch_uuid' => $branch->uuid, 'product_uuid' => $product->uuid];
        $this->postJson('/api/v1/admin/inventory', $payload)->assertUnprocessable();
        $product->update(['tracks_inventory' => true]);
        $this->postJson('/api/v1/admin/inventory', $payload)->assertCreated()->assertJsonPath('data.stock.on_hand', '0.000');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[DataProvider('stockOperations')]
    public function test_stock_operations_update_exact_balances_and_append_history(string $operation, string $quantity, string $expected, string $type): void
    {
        $this->admin();
        $inventory = BranchInventory::factory()->create(['on_hand' => '10.000', 'reserved' => '2.000']);
        $this->postJson('/api/v1/admin/inventory/'.$inventory->uuid.'/'.$operation, ['quantity' => $quantity, 'reason' => 'جرد المستودع'])
            ->assertOk()->assertJsonPath('data.stock.on_hand', $expected)->assertJsonPath('data.stock.reserved', '2.000');
        $this->assertSame($type, StockMovement::firstOrFail()->type->value);
        $this->assertSame('10.000', StockMovement::firstOrFail()->on_hand_before);
        $this->assertSame($expected, StockMovement::firstOrFail()->on_hand_after);
    }

    public static function stockOperations(): array
    {
        return [['receive', '0.001', '10.001', 'stock_in'], ['receive', '3.333', '13.333', 'stock_in'],
            ['adjust', '2.125', '12.125', 'adjustment_in'], ['adjust', '-2.125', '7.875', 'adjustment_out'],
            ['waste', '0.001', '9.999', 'waste'], ['waste', '8', '2.000', 'waste']];
    }

    #[DataProvider('invalidOperations')]
    public function test_invalid_mutations_leave_balances_and_ledger_unchanged(string $operation, mixed $quantity, ?string $reason): void
    {
        $this->admin();
        $inventory = BranchInventory::factory()->create(['on_hand' => '10.000', 'reserved' => '2.000']);
        $this->postJson('/api/v1/admin/inventory/'.$inventory->uuid.'/'.$operation, ['quantity' => $quantity, 'reason' => $reason])->assertUnprocessable();
        $this->assertSame('10.000', $inventory->fresh()->on_hand);
        $this->assertSame('2.000', $inventory->fresh()->reserved);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function invalidOperations(): array
    {
        return [['receive', '0', null], ['receive', '-1', null], ['receive', '0.0001', null], ['receive', '1000000000', null],
            ['receive', [], null], ['adjust', '0', 'جرد'], ['adjust', '1', null], ['adjust', '-8.001', 'جرد'],
            ['adjust', '-11', 'جرد'], ['waste', '-1', 'هدر'], ['waste', '0', 'هدر'], ['waste', '8.001', 'هدر'],
            ['waste', '1', null], ['waste', '1', 'x'], ['adjust', '1', 'x']];
    }

    public function test_configuration_cannot_overwrite_balances_and_can_disable_tracking(): void
    {
        $this->admin();
        $inventory = BranchInventory::factory()->create();
        foreach (['on_hand', 'reserved', 'available', 'opening_balance', 'branch_uuid', 'product_uuid'] as $field) {
            $this->patchJson('/api/v1/admin/inventory/'.$inventory->uuid, [$field => 5])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/admin/inventory/'.$inventory->uuid, ['low_stock_threshold' => '2.125', 'is_tracking' => false])
            ->assertOk()->assertJsonPath('data.low_stock_threshold', '2.125')->assertJsonPath('data.tracking', false)
            ->assertJsonPath('data.stock.on_hand', '20.000');
        $this->patchJson('/api/v1/admin/inventory/'.$inventory->uuid, ['low_stock_threshold' => null])->assertOk()
            ->assertJsonPath('data.low_stock_threshold', null);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->deleteJson('/api/v1/admin/inventory/'.$inventory->uuid)->assertStatus(405);
    }

    public function test_filters_sorting_pagination_reports_and_branch_isolation(): void
    {
        $this->admin();
        $target = BranchInventory::factory()->lowStock()->create(['on_hand' => '3.000']);
        $target->product->update(['sku' => 'INVENTORY-SEARCH-UNIQUE']);
        BranchInventory::factory()->for($target->branch)->outOfStock()->create();
        BranchInventory::factory()->for($target->branch)->notTracking()->outOfStock()->create();
        BranchInventory::factory()->outOfStock()->create();
        $query = '?branch_uuid='.$target->branch->uuid;
        $this->getJson('/api/v1/admin/inventory'.$query.'&low_stock=1&sort=available')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.stock.available', '0.000')->assertJsonPath('data.1.uuid', $target->uuid);
        $this->getJson('/api/v1/admin/inventory'.$query.'&out_of_stock=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory?product_uuid='.$target->product->uuid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory?category_uuid='.$target->product->category->uuid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory?'.http_build_query(['search' => $target->product->sku]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory'.$query.'&per_page=1&page=2&sort=product_name&direction=desc')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/reports/inventory'.$query)->assertOk()
            ->assertJsonPath('data.tracked_products_count', 2)->assertJsonPath('data.low_stock_count', 2)->assertJsonPath('data.out_of_stock_count', 1);
        $this->getJson('/api/v1/admin/inventory?sort=on_hand desc;DROP TABLE users')->assertUnprocessable();
        $this->getJson('/api/v1/admin/inventory?direction=garbage&per_page=101')->assertUnprocessable();
    }

    public function test_movement_history_filters_dates_and_remains_readable_after_soft_deletion(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create();
        $this->travelTo(now()->startOfDay()->addHours(4));
        app(ReceiveStockAction::class)->handle($inventory, '1.001', $actor, 'استلام');
        app(AdjustStockAction::class)->handle($inventory, '-1.000', $actor, 'جرد');
        $url = '/api/v1/admin/inventory/'.$inventory->uuid.'/movements';
        $this->getJson($url.'?type=stock_in&date_from='.now()->toDateString().'&date_to='.now()->toDateString())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.created_by', $actor->uuid)->assertJsonPath('data.0.quantity', '1.001');
        $inventory->product->delete();
        $inventory->branch->delete();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/inventory/movements?branch_uuid='.$inventory->branch->uuid)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'?type=invalid&date_from=2026-10-03&date_to=2026-10-02')->assertUnprocessable();
        $this->getJson($url.'?date_from=2020-01-01&date_to=2020-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->travelBack();
    }

    #[DataProvider('restrictedRoles')]
    public function test_customer_cashier_and_kitchen_have_no_inventory_management_permissions(string $role): void
    {
        $this->admin($role);
        $inventory = BranchInventory::factory()->create();
        foreach (['/inventory', '/inventory/'.$inventory->uuid, '/inventory/movements', '/reports/inventory'] as $path) {
            $this->getJson('/api/v1/admin'.$path)->assertForbidden();
        }
        foreach (['receive', 'adjust', 'waste'] as $operation) {
            $this->postJson('/api/v1/admin/inventory/'.$inventory->uuid.'/'.$operation, ['quantity' => 1, 'reason' => 'جرد'])->assertForbidden();
        }
        $this->postJson('/api/v1/admin/inventory', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/inventory/'.$inventory->uuid, [])->assertForbidden();
    }

    public static function restrictedRoles(): array
    {
        return [['customer'], ['cashier'], ['kitchen']];
    }

    public function test_guests_are_unauthorized_and_individual_permissions_do_not_grant_mutation(): void
    {
        $this->getJson('/api/v1/admin/inventory')->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo('inventory.view');
        $inventory = BranchInventory::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/inventory')->assertOk();
        $this->postJson('/api/v1/admin/inventory/'.$inventory->uuid.'/receive', ['quantity' => 1])->assertForbidden();
        $this->getJson('/api/v1/admin/inventory/movements')->assertForbidden();
    }

    public function test_dashboard_reveals_inventory_only_with_inventory_view(): void
    {
        $this->admin('cashier');
        BranchInventory::factory()->outOfStock()->create();
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonMissingPath('data.summary.inventory');
        $this->admin('manager');
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.summary.inventory.out_of_stock_count', 1);
    }

    public function test_threshold_alert_fires_once_per_crossing_and_recovers_before_rearming(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create(['on_hand' => '6.000', 'low_stock_threshold' => '5.000']);
        Event::fake([LowStockDetected::class, StockRecovered::class]);
        app(AdjustStockAction::class)->handle($inventory, '-1', $actor, 'جرد');
        app(AdjustStockAction::class)->handle($inventory, '-1', $actor, 'جرد');
        Event::assertDispatchedTimes(LowStockDetected::class, 1);
        app(ReceiveStockAction::class)->handle($inventory, '3', $actor);
        Event::assertDispatchedTimes(StockRecovered::class, 1);
        app(AdjustStockAction::class)->handle($inventory, '-3', $actor, 'جرد');
        Event::assertDispatchedTimes(LowStockDetected::class, 2);
        $this->assertDatabaseCount('outbound_notifications', 0);
    }

    public function test_rollback_does_not_emit_threshold_event_or_leave_movement(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create(['on_hand' => '6.000', 'low_stock_threshold' => '5.000']);
        Event::fake([LowStockDetected::class, StockRecovered::class]);
        try {
            DB::transaction(function () use ($actor, $inventory): void {
                app(AdjustStockAction::class)->handle($inventory, '-2', $actor, 'جرد');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        Event::assertNotDispatched(LowStockDetected::class);
        $this->assertSame('6.000', $inventory->fresh()->on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_disabled_tracking_and_absent_threshold_never_emit_low_alert(): void
    {
        $actor = $this->admin();
        Event::fake([LowStockDetected::class]);
        foreach ([['is_tracking' => false], ['low_stock_threshold' => null]] as $state) {
            $inventory = BranchInventory::factory()->create($state + ['on_hand' => '6.000']);
            app(AdjustStockAction::class)->handle($inventory, '-6', $actor, 'جرد');
        }
        Event::assertNotDispatched(LowStockDetected::class);
    }

    #[DataProvider('invalidDatabaseBalances')]
    public function test_database_prevents_invalid_balances_even_without_eloquent(array $values): void
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => '10.000', 'reserved' => '2.000']);
        $this->expectException(QueryException::class);
        DB::table('branch_inventories')->where('id', $inventory->id)->update($values);
    }

    public static function invalidDatabaseBalances(): array
    {
        return [[['on_hand' => -1]], [['reserved' => -1]], [['reserved' => 11]], [['low_stock_threshold' => -1]]];
    }

    public function test_ledger_cannot_be_updated_or_deleted_through_model(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create();
        app(ReceiveStockAction::class)->handle($inventory, '1', $actor);
        $movement = StockMovement::firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $movement->update(['reason' => 'تغيير']) : $movement->delete();
                $this->fail('Ledger must be immutable.');
            } catch (\LogicException) {
                $this->assertDatabaseCount('stock_movements', 1);
            }
        }
    }

    public function test_thousandths_are_exact_and_maximum_receipt_cannot_overflow(): void
    {
        $actor = $this->admin();
        $this->assertSame(1001, StockQuantity::minor('1.001'));
        $this->assertSame('-0.001', StockQuantity::decimal(-1));
        $inventory = BranchInventory::factory()->create(['on_hand' => '999999999.998']);
        app(ReceiveStockAction::class)->handle($inventory, '0.001', $actor);
        $this->assertSame('999999999.999', $inventory->fresh()->on_hand);
        $this->postJson('/api/v1/admin/inventory/'.$inventory->uuid.'/receive', ['quantity' => '0.001'])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_detail_limits_recent_history_and_uses_public_identifiers(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create();
        for ($index = 0; $index < 12; $index++) {
            app(ReceiveStockAction::class)->handle($inventory, '0.001', $actor);
        }
        $this->getJson('/api/v1/admin/inventory/'.$inventory->uuid)->assertOk()
            ->assertJsonPath('data.id', $inventory->uuid)->assertJsonCount(10, 'data.recent_movements')
            ->assertJsonMissingPath('data.branch.id')->assertJsonMissingPath('data.product.id')
            ->assertJsonMissingPath('data.recent_movements.0.branch_inventory_id');
        $this->getJson('/api/v1/admin/inventory?tracking=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/inventory?tracking=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory/'.$inventory->uuid.'/movements?date_to='.now()->addDay()->toDateString())
            ->assertOk()->assertJsonCount(12, 'data');
    }

    public function test_product_tracking_can_be_updated_only_by_authorized_admin(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        $this->assertFalse($product->fresh()->tracks_inventory);
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['tracks_inventory' => true])
            ->assertOk()->assertJsonPath('data.tracks_inventory', true);
        $this->assertTrue($product->fresh()->tracks_inventory);
        $this->assertDatabaseCount('branch_inventories', 0);
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['tracks_inventory' => 'bad'])->assertUnprocessable();
        $this->admin('customer');
        $this->patchJson('/api/v1/admin/products/'.$product->uuid, ['tracks_inventory' => false])->assertForbidden();
        $this->assertTrue($product->fresh()->tracks_inventory);
    }

    public function test_restrict_foreign_keys_prevent_deleting_stock_history(): void
    {
        $actor = $this->admin();
        $inventory = BranchInventory::factory()->create();
        app(ReceiveStockAction::class)->handle($inventory, '1', $actor);
        foreach ([['branch_inventories', $inventory->id], ['products', $inventory->product_id], ['branches', $inventory->branch_id]] as [$table,$id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('History must survive deletion attempts.');
            } catch (QueryException) {
                $this->assertDatabaseCount('stock_movements', 1);
            }
        }
    }
}
