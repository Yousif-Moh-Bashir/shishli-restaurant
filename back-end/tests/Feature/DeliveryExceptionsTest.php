<?php

namespace Tests\Feature;

use App\Actions\Drivers\ManageDeliveryExceptionAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use App\Services\OrderTrackingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryExceptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function driver(?Branch $branch = null): User
    {
        $driver = User::factory()->create()->assignRole('driver');
        $driver->forceFill(['driver_branch_id' => ($branch ?? Branch::factory()->create())->id,
            'driver_enabled' => true, 'driver_available' => true])->save();

        return $driver;
    }

    private function order(User $driver, array $attributes = []): Order
    {
        return Order::factory()->delivery()->create(array_merge(['driver_id' => $driver->id,
            'branch_id' => $driver->driver_branch_id, 'status' => 'out_for_delivery', 'delivery_status' => 'on_the_way',
            'payment_method' => 'network', 'payment_status' => 'pending', 'total' => '57.00',
            'delivery_picked_up_at' => now()->subHours(3), 'placed_at' => now()->subDay()], $attributes))->fresh();
    }

    private function staff(string $role = 'super_admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function payload(Order $order, string $action): array
    {
        return ['action' => $action, 'reason' => 'تم التحقق من العهدة والحالة الفعلية', 'expected_revision' => $order->fresh()->delivery_revision,
            'operation_key' => (string) Str::uuid(), 'acknowledged' => true];
    }

    private function path(Order $order): string
    {
        return '/api/v1/admin/orders/'.$order->uuid.'/delivery-exception';
    }

    public function test_network_order_payment_is_confirmed_by_its_driver_without_cashier(): void
    {
        $this->freezeTime();
        $driver = $this->driver();
        $order = $this->order($driver, ['order_number' => 'SH-0002']);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders/'.$order->uuid)->assertOk()
            ->assertJsonPath('data.delivery_block.code', 'NETWORK_CONFIRMATION_REQUIRED')
            ->assertJsonPath('data.delivery_block.responsible', 'driver')->assertJsonPath('data.cash_due', '0.00')
            ->assertJsonPath('data.available_actions', ['failed', 'confirm_network'])->assertJsonPath('data.can_request_intervention', true);
        $this->postJson('/api/v1/driver/orders/'.$order->uuid.'/transition', ['status' => 'delivered'])->assertUnprocessable();
        $this->postJson('/api/v1/driver/orders/'.$order->uuid.'/network/confirm')->assertUnprocessable()->assertJsonValidationErrors('network_verified');
        $this->postJson('/api/v1/driver/orders/'.$order->uuid.'/network/confirm', ['network_verified' => true])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.available_actions', ['delivered', 'failed']);
        $this->postJson('/api/v1/driver/orders/'.$order->uuid.'/transition', ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.preparation_status', 'completed')->assertJsonPath('data.delivery_status', 'delivered');
        $next = $this->order($driver, ['driver_id' => null, 'status' => 'ready', 'delivery_status' => null, 'placed_at' => now()]);
        $this->postJson('/api/v1/driver/orders/'.$next->uuid.'/accept')->assertConflict();
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_network_confirmation_requires_explicit_verification_and_preserves_single_sale(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        Payment::factory()->cash()->create(['order_id' => $order->id, 'method' => 'network', 'amount' => '57.00', 'currency' => 'SAR']);
        $url = '/api/v1/driver/orders/'.$order->uuid.'/network/confirm';
        $this->actingAs($driver)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('network_verified');
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->actingAs($driver)->postJson($url, ['network_verified' => true])->assertOk();
        $this->postJson($url, ['network_verified' => true])->assertOk();
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'payments.confirm_network']);
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->actingAs($driver)->postJson('/api/v1/driver/orders/'.$order->uuid.'/transition', ['status' => 'delivered'])->assertOk();
        $this->assertFalse(app(DriverOrderService::class)->active($driver)->exists());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_intervention_is_owned_idempotent_and_does_not_change_payment(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $url = '/api/v1/driver/orders/'.$order->uuid.'/intervention';
        $payload = $this->payload($order, 'request_intervention');
        $this->actingAs($this->driver($driver->driverBranch))->postJson($url, $payload)->assertNotFound();
        $this->actingAs($driver)->postJson($url, $payload)->assertOk()->assertJsonPath('data.can_request_intervention', false);
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('delivery_operations', 1);
        $this->assertDatabaseCount('order_delivery_histories', 1);
        $this->assertSame(DeliveryStatus::OnTheWay, $order->fresh()->delivery_status);
        $this->assertSame('pending', $order->fresh()->payment_status->value);
    }

    #[DataProvider('methods')]
    public function test_return_then_exceptional_cancel_releases_driver_without_financial_or_stock_mutation(string $method, string $paymentStatus): void
    {
        $this->freezeTime();
        $driver = $this->driver();
        $order = $this->order($driver, ['payment_method' => $method, 'payment_status' => $paymentStatus]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'method' => $method, 'status' => $paymentStatus, 'amount' => '57.00']);
        $before = $payment->fresh()->getRawOriginal();
        $actor = $this->staff();
        $actor->givePermissionTo('delivery_exceptions.cancel');
        $this->actingAs($actor);
        $this->postJson($this->path($order), $this->payload($order, 'cancel'))->assertConflict();
        $this->postJson($this->path($order), $this->payload($order, 'fail'))->assertOk();
        $this->assertTrue(app(DriverOrderService::class)->active($driver)->exists());
        $this->postJson($this->path($order), $this->payload($order, 'release'))->assertConflict();
        $this->postJson($this->path($order), $this->payload($order, 'start_return'))->assertOk();
        $this->assertTrue(app(DriverOrderService::class)->active($driver)->exists());
        $this->postJson($this->path($order), $this->payload($order, 'confirm_return'))->assertOk();
        $this->assertFalse(app(DriverOrderService::class)->active($driver)->exists());
        $this->assertNull($order->fresh()->delivery_delivered_at);
        $this->assertNull($order->fresh()->completed_at);
        $this->postJson($this->path($order), $this->payload($order, 'cancel'))->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(DeliveryStatus::Returned, $order->fresh()->delivery_status);
        if ($paymentStatus === 'pending') {
            $before['status'] = 'cancelled';
            $before['cancelled_at'] = now()->format('Y-m-d H:i:s');
        }
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function methods(): array
    {
        return [['network', 'pending'], ['cash', 'paid'], ['card', 'paid']];
    }

    public function test_release_before_pickup_keeps_assignment_history_and_allows_new_task(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => 'ready', 'delivery_status' => 'assigned', 'delivery_picked_up_at' => null]);
        $this->actingAs($this->staff());
        $this->postJson($this->path($order), $this->payload($order, 'fail'))->assertOk();
        $this->postJson($this->path($order), $this->payload($order, 'release'))->assertOk();
        $this->assertSame($driver->id, $order->fresh()->driver_id);
        $next = $this->order($driver, ['status' => 'ready', 'driver_id' => null, 'delivery_status' => null, 'placed_at' => now()]);
        $this->actingAs($driver)->postJson('/api/v1/driver/orders/'.$next->uuid.'/accept')->assertOk();
    }

    public function test_reassignment_revokes_old_access_and_rejects_stale_admin_action(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $target = $this->driver($driver->driverBranch);
        $stale = $this->payload($order, 'fail');
        $payload = $this->payload($order, 'reassign') + ['driver_uuid' => $target->uuid];
        $this->actingAs($this->staff())->postJson($this->path($order), $payload)->assertOk();
        $this->postJson($this->path($order), $payload)->assertOk();
        $this->postJson($this->path($order), $stale)->assertConflict();
        $this->assertFalse(app(DriverOrderService::class)->active($driver)->exists());
        $this->assertTrue(app(DriverOrderService::class)->active($target)->exists());
        $this->actingAs($driver)->getJson('/api/v1/driver/orders/'.$order->uuid)->assertNotFound();
    }

    public function test_delivered_completed_and_cancelled_orders_cannot_be_reassigned_or_processed(): void
    {
        $driver = $this->driver();
        $target = $this->driver($driver->driverBranch);
        $this->actingAs($this->staff());
        foreach (['out_for_delivery', 'completed', 'cancelled'] as $status) {
            $order = $this->order($driver, ['status' => $status, 'delivery_status' => 'delivered', 'payment_status' => 'paid']);
            $this->postJson($this->path($order), $this->payload($order, 'fail'))->assertConflict();
            $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/reassign-driver', ['driver_uuid' => $target->uuid, 'reason' => 'نقل المهمة'])->assertConflict();
            $this->assertSame('paid', $order->fresh()->payment_status->value);
            $this->assertSame('delivered', $order->fresh()->delivery_status->value);
        }
    }

    public function test_permissions_validation_and_operation_key_reuse_are_enforced(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $url = $this->path($order);
        $this->postJson($url, $this->payload($order, 'fail'))->assertUnauthorized();
        $this->actingAs($driver)->postJson($url, $this->payload($order, 'fail'))->assertForbidden();
        $this->actingAs($this->staff('cashier'))->postJson($url, $this->payload($order, 'fail'))->assertForbidden();
        $this->actingAs($this->staff())->postJson($url, [])->assertUnprocessable();
        $payload = $this->payload($order, 'fail');
        $this->postJson($url, $payload)->assertOk();
        $payload['reason'] = 'سبب مختلف لنفس المفتاح';
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('delivery_operations', 1);
    }

    public function test_exception_list_includes_previous_days_and_tracks_customer_safe_delivery_state(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $this->actingAs($this->staff())->getJson('/api/v1/admin/delivery-exceptions')->assertOk()->assertJsonPath('data.0.id', $order->uuid);
        $this->postJson($this->path($order), $this->payload($order, 'start_return'))->assertOk();
        $tracking = app(OrderTrackingService::class)->buildTrackingData($order->fresh());
        $this->assertSame('returning', $tracking->order['delivery_state']['status']);
        $this->assertArrayNotHasKey('reason', $tracking->order['delivery_state']);
    }

    #[Group('delivery-sqlite')]
    public function test_audit_failure_rolls_back_task_history_and_idempotency_record(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $driver = $this->driver();
        $order = $this->order($driver);
        $this->actingAs($this->staff());
        DB::statement("CREATE TRIGGER reject_delivery_audit BEFORE INSERT ON admin_activity_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        $this->postJson($this->path($order), $this->payload($order, 'fail'))->assertStatus(500);
        $this->assertSame(DeliveryStatus::OnTheWay, $order->fresh()->delivery_status);
        $this->assertDatabaseCount('delivery_operations', 0);
        $this->assertDatabaseCount('order_delivery_histories', 0);
        DB::statement('DROP TRIGGER reject_delivery_audit');
    }

    public function test_custody_loss_needs_separate_permission_and_evidence_without_fake_return_or_payment(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['delivery_status' => 'failed']);
        $limited = $this->staff('cashier');
        $limited->givePermissionTo(['orders.view', 'delivery_exceptions.release']);
        $payload = $this->payload($order, 'resolve_custody') + ['custody_outcome' => 'lost'];
        $this->actingAs($limited)->postJson($this->path($order), $payload)->assertForbidden();
        $this->actingAs($this->staff())->postJson($this->path($order), $this->payload($order, 'resolve_custody'))->assertUnprocessable();
        $this->postJson($this->path($order), $payload)->assertOk()->assertJsonPath('data.delivery.status', 'released');
        $this->postJson($this->path($order), $payload)->assertOk();
        $this->assertFalse(app(DriverOrderService::class)->active($driver)->exists());
        $this->assertSame('pending', $order->fresh()->payment_status->value);
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_delivered_at);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'delivery_exceptions.resolve_custody']);
        $this->assertDatabaseCount('delivery_operations', 1);
        $this->assertDatabaseCount('order_delivery_histories', 1);
        $this->assertStringContainsString('مفقود', $order->fresh()->delivery_exception_reason);
    }

    public function test_normal_cancellation_cannot_release_picked_up_task_without_resolving_custody(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => 'ready', 'delivery_status' => 'picked_up']);
        $this->actingAs($this->staff())->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'إلغاء دون حسم العهدة'])->assertConflict();
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->assertTrue(app(DriverOrderService::class)->active($driver)->exists());
    }

    public function test_card_cannot_use_offline_collection_and_driver_cannot_use_admin_payment_endpoint(): void
    {
        $driver = $this->driver();
        $network = $this->order($driver);
        $card = $this->order($driver, ['payment_method' => 'card']);
        $this->actingAs($driver)->postJson('/api/v1/admin/orders/'.$network->uuid.'/payments/cash/collect', ['network_verified' => true])->assertForbidden();
        $this->actingAs($this->staff('cashier'))->postJson('/api/v1/admin/orders/'.$card->uuid.'/payments/cash/collect', ['network_verified' => true])->assertUnprocessable();
        $this->assertSame('pending', $network->fresh()->payment_status->value);
        $this->assertSame('pending', $card->fresh()->payment_status->value);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    #[Group('delivery-sqlite')]
    public function test_upgrade_preserves_existing_completed_and_active_order_rows(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $driver = $this->driver();
        $completed = $this->order($driver, ['status' => 'completed', 'delivery_status' => 'delivered', 'payment_status' => 'paid']);
        $active = $this->order($driver);
        $migration = require database_path('migrations/2026_10_21_201116_add_delivery_exception_management.php');
        $migration->down();
        $before = DB::table('orders')->whereIn('id', [$completed->id, $active->id])->orderBy('id')->get()->toArray();
        $migration->up();
        foreach ($before as $row) {
            $after = (array) DB::table('orders')->where('id', $row->id)->first();
            $this->assertSame((array) $row, array_intersect_key($after, (array) $row));
            $this->assertSame(0, $after['delivery_revision']);
            $this->assertNull($after['delivery_task_closed_at']);
        }
    }

    public function test_standard_cancellation_preserves_already_resolved_delivery_custody(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => 'ready', 'delivery_status' => 'returned', 'delivery_task_closed_at' => now()]);
        $this->actingAs($this->staff())->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'إلغاء بعد استلام المطعم'])->assertOk();
        $this->assertSame(DeliveryStatus::Returned, $order->fresh()->delivery_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_delivered_at);
    }

    public function test_reassignment_replay_with_stale_model_returns_the_original_success_without_a_second_transfer(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $target = $this->driver($driver->driverBranch);
        $actor = $this->staff();
        $payload = $this->payload($order, 'reassign') + ['driver_uuid' => $target->uuid];
        $action = app(ManageDeliveryExceptionAction::class);
        $first = $action->handle($actor, $order, $payload);
        $second = $action->handle($actor, $order, $payload);
        $this->assertSame($target->id, $first->driver_id);
        $this->assertSame($target->id, $second->driver_id);
        $this->assertDatabaseCount('delivery_operations', 1);
        $this->assertDatabaseCount('order_delivery_histories', 1);
    }

    #[Group('delivery-sqlite')]
    public function test_partial_migration_can_be_resumed_without_resetting_old_data_or_custom_permissions(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $driver = $this->driver();
        $order = $this->order($driver);
        $role = Role::findByName('manager', 'web');
        $role->permissions_customized = true;
        $role->save();
        $role->syncPermissions(['orders.view']);
        $migration = require database_path('migrations/2026_10_21_201116_add_delivery_exception_management.php');
        $migration->down();
        Schema::table('orders', fn ($table) => $table->unsignedInteger('delivery_revision')->default(0));
        $migration->up();
        $migration->up();
        $this->assertSame('pending', $order->fresh()->payment_status->value);
        $this->assertSame('on_the_way', $order->fresh()->delivery_status->value);
        $this->assertSame(['orders.view'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertSame(6, Permission::where('name', 'like', 'delivery_exceptions.%')->count());
        $defaultManager = $this->staff('manager');
        $this->assertFalse($defaultManager->can('delivery_exceptions.resolve_custody'));
    }

    #[Group('delivery-sqlite')]
    public function test_existing_roles_do_not_receive_cancel_automatically_and_keep_explicit_grants(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $superAdmin = Role::findByName('super_admin', 'web');
        $manager = Role::findByName('manager', 'web');
        $migration = require database_path('migrations/2026_10_21_201116_add_delivery_exception_management.php');
        $migration->down();
        $migration->up();

        $this->assertFalse($superAdmin->fresh()->hasPermissionTo('delivery_exceptions.cancel'));
        $this->assertFalse($manager->fresh()->hasPermissionTo('delivery_exceptions.cancel'));

        $migration->down();
        $cancel = Permission::findByName('delivery_exceptions.cancel', 'web');
        $superAdmin->givePermissionTo($cancel);
        $manager->givePermissionTo($cancel);

        $migration->up();
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($superAdmin->fresh()->hasPermissionTo('delivery_exceptions.cancel'));
        $this->assertTrue($manager->fresh()->hasPermissionTo('delivery_exceptions.cancel'));
    }
}
