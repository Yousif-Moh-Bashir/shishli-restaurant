<?php

namespace Tests\Feature;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DriverCashCollection;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InternalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function driver(?Branch $branch = null): User
    {
        $driver = User::factory()->create();
        $driver->assignRole('driver');
        $driver->forceFill(['driver_branch_id' => ($branch ?? Branch::factory()->create())->id, 'driver_enabled' => true, 'driver_available' => true])->save();

        return $driver;
    }

    private function order(User $driver, array $attributes = []): Order
    {
        return Order::factory()->delivery()->create(array_merge(['branch_id' => $driver->driver_branch_id, 'status' => OrderStatus::Ready,
            'total' => '85.00', 'delivery_fee' => '5.00'], $attributes));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function url(Order $order, string $action = ''): string
    {
        return '/api/v1/driver/orders/'.$order->uuid.$action;
    }

    public function test_guest_and_customer_cannot_enter_driver_endpoints(): void
    {
        $this->getJson('/api/v1/driver/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/driver/orders')->assertForbidden();
    }

    public function test_login_and_restored_session_identify_driver_without_admin_access(): void
    {
        $driver = $this->driver();
        $driver->update(['password' => 'password']);
        $login = $this->postJson('/api/v1/login', ['email' => $driver->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.is_driver', true);
        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('data.token'))->getJson('/api/user')->assertOk()->assertJsonPath('data.is_driver', true);
        $this->getJson('/api/v1/driver/me')->assertOk();
        $this->getJson('/api/v1/admin/me')->assertForbidden();
    }

    public function test_delivery_and_final_completion_require_ownership_payment_and_delivery_confirmation(): void
    {
        $driver = $this->driver();
        $other = $this->driver($driver->driverBranch);
        $order = $this->order($driver, ['driver_id' => $driver->id, 'status' => 'out_for_delivery', 'delivery_status' => 'on_the_way']);
        $this->actingAs($other)->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertNotFound();
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier)->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertConflict();
        $order->update(['payment_status' => 'paid']);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertConflict();
        $this->actingAs($driver)->getJson($this->url($order))->assertOk()->assertJsonFragment(['available_actions' => ['delivered', 'failed']]);
        $this->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.preparation_status', 'out_for_delivery')->assertJsonPath('data.delivery_status', 'delivered');
        $this->assertNotNull($order->fresh()->delivery_delivered_at);
        $this->assertNull($order->fresh()->completed_at);
        $this->actingAs($cashier)->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertOk();
        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_driver_cannot_confirm_delivery_before_dispatch_even_if_delivery_stage_is_inconsistent(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'on_the_way', 'payment_status' => 'paid']);
        $this->actingAs($driver)->getJson($this->url($order))->assertOk()->assertJsonPath('data.available_actions', ['failed']);
        $this->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertConflict();
        $this->assertNull($order->fresh()->delivery_delivered_at);
        $this->assertDatabaseCount('order_delivery_histories', 0);
    }

    public function test_driver_permissions_do_not_grant_admin_access(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver)->getJson('/api/v1/admin/me')->assertForbidden();
        $this->getJson('/api/v1/admin/drivers')->assertForbidden();
        $this->getJson('/api/v1/driver/me')->assertOk()->assertJsonPath('data.branch.id', $driver->driverBranch->uuid);
        $driver->givePermissionTo('drivers.view');
        $this->getJson('/api/v1/admin/drivers')->assertOk();
    }

    public function test_available_list_is_branch_scoped_and_redacts_customer_details(): void
    {
        $this->freezeTime();
        $driver = $this->driver();
        $ready = $this->order($driver);
        $soon = $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => now()->addMinutes(10)]);
        $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => now()->addMinutes(16)]);
        $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => null]);
        $this->order($driver, ['type' => 'pickup']);
        $this->order($driver, ['status' => OrderStatus::Pending]);
        $this->order($this->driver());
        $response = $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$ready->uuid, $soon->uuid], array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $row) {
            foreach (['customer', 'address', 'notes', 'total', 'cash_due', 'branch_location', 'customer_phone', 'customer_name'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
        $this->getJson($this->url($ready))->assertNotFound();
    }

    public function test_preparing_order_cannot_be_accepted_until_kitchen_marks_it_ready(): void
    {
        $this->freezeTime();
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => now()->addMinutes(10)]);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()
            ->assertJsonPath('data.0.id', $order->uuid)->assertJsonPath('data.0.available_actions', []);
        $this->postJson($this->url($order, '/accept'))->assertConflict();
        $this->assertNull($order->fresh()->driver_id);
        $this->assertDatabaseCount('order_delivery_histories', 0);
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$order->uuid.'/mark-ready')->assertOk();
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()
            ->assertJsonPath('data.0.available_actions', ['accept']);
        $this->postJson($this->url($order, '/accept'))->assertOk()->assertJsonPath('data.delivery_status', 'assigned');
    }

    public function test_accept_records_driver_and_time_and_removes_order_from_other_lists(): void
    {
        $this->freezeTime();
        $first = $this->driver();
        $second = $this->driver($first->driverBranch);
        $order = $this->order($first);
        $this->actingAs($first)->postJson($this->url($order, '/accept'))->assertOk()
            ->assertJsonPath('data.customer.name', $order->customer_name)->assertJsonPath('data.delivery_status', 'assigned');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'driver_id' => $first->id, 'driver_accepted_at' => now()->format('Y-m-d H:i:s')]);
        $this->assertDatabaseHas('order_delivery_histories', ['order_id' => $order->id, 'changed_by' => $first->id, 'to_status' => 'assigned']);
        $this->actingAs($second)->getJson('/api/v1/driver/orders')->assertJsonCount(0, 'data');
        $this->getJson($this->url($order))->assertNotFound();
        $this->postJson($this->url($order, '/accept'))->assertConflict();
        $this->assertDatabaseCount('order_delivery_histories', 1);
    }

    public function test_driver_cannot_accept_two_active_orders_or_accept_twice(): void
    {
        $driver = $this->driver();
        $first = $this->order($driver);
        $second = $this->order($driver);
        $this->actingAs($driver)->postJson($this->url($first, '/accept'))->assertOk();
        $this->postJson($this->url($second, '/accept'))->assertConflict();
        $this->postJson($this->url($first, '/accept'))->assertConflict();
        $this->assertNull($second->fresh()->driver_id);
    }

    public function test_other_branch_and_unavailable_drivers_cannot_accept(): void
    {
        $driver = $this->driver();
        $foreign = $this->order($this->driver());
        $this->actingAs($driver)->postJson($this->url($foreign, '/accept'))->assertNotFound();
        $own = $this->order($driver);
        $driver->forceFill(['driver_available' => false])->save();
        $this->postJson($this->url($own, '/accept'))->assertForbidden();
        $this->getJson('/api/v1/driver/orders')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->uuid)->assertJsonPath('data.0.available_actions', [])
            ->assertJsonPath('driver_context.can_accept', false);
        $this->assertNull($own->fresh()->driver_id);
    }

    public function test_ready_order_appears_after_real_kitchen_transition_even_without_estimate(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => OrderStatus::Pending, 'estimated_ready_at' => null]);
        $admin = $this->admin();
        foreach (['confirm', 'start-preparing'] as $action) {
            $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.$order->uuid.'/'.$action)->assertOk();
            $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertJsonCount(0, 'data');
        }
        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.$order->uuid.'/mark-ready')->assertOk();
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->uuid)->assertJsonPath('data.0.preparation_status', 'ready')
            ->assertJsonPath('data.0.delivery_status_label', 'بانتظار المندوب')
            ->assertJsonPath('data.0.available_actions', ['accept'])->assertJsonPath('driver_context.can_accept', true);
    }

    public function test_busy_driver_sees_ready_orders_without_accept_action_and_can_find_current_order(): void
    {
        $driver = $this->driver();
        $active = $this->order($driver);
        $ready = $this->order($driver);
        $this->actingAs($driver)->postJson($this->url($active, '/accept'))->assertOk();
        $this->getJson('/api/v1/driver/orders')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->uuid)->assertJsonPath('data.0.available_actions', [])
            ->assertJsonPath('driver_context.can_accept', false)->assertJsonPath('driver_context.active_order.id', $active->uuid);
        $this->postJson($this->url($ready, '/accept'))->assertConflict();
        $this->getJson('/api/v1/driver/orders?scope=mine')->assertOk()->assertJsonPath('data.0.id', $active->uuid);
    }

    public function test_stopped_driver_sees_branch_queue_with_explanation_but_cannot_accept(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $driver->forceFill(['driver_enabled' => false])->save();
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available_actions', [])->assertJsonPath('driver_context.can_accept', false);
        $this->postJson($this->url($order, '/accept'))->assertForbidden();
    }

    public function test_ready_orders_are_first_and_oldest_ready_order_is_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'UTC'));
        $driver = $this->driver();
        $soon = $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => now()->addMinutes(5), 'placed_at' => now()->subHours(3)]);
        $newer = $this->order($driver, ['placed_at' => now()->subHour()]);
        $older = $this->order($driver, ['placed_at' => now()->subHours(2)]);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()
            ->assertJsonPath('data.0.id', $older->uuid)->assertJsonPath('data.1.id', $newer->uuid)->assertJsonPath('data.2.id', $soon->uuid);
    }

    public function test_admin_cannot_send_ready_order_without_driver_and_order_stays_visible(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$order->uuid.'/dispatch')->assertConflict();
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->assertNull($order->fresh()->driver_id);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()->assertJsonPath('data.0.id', $order->uuid)
            ->assertJsonPath('data.0.available_actions', ['accept']);
    }

    public function test_driver_current_workday_excludes_previous_queue_and_history_is_read_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 15:00', 'Asia/Riyadh'));
        $driver = $this->driver();
        app(ReplaceBusinessHoursAction::class)->handle($driver->driverBranch, [
            ['day_of_week' => 1, 'opens_at' => '12:00', 'closes_at' => '02:00'],
            ['day_of_week' => 2, 'opens_at' => '12:00', 'closes_at' => '02:00'],
        ]);
        $previous = $this->order($driver, ['placed_at' => CarbonImmutable::parse('2026-10-06 01:59:59', 'Asia/Riyadh')->utc()]);
        $current = $this->order($driver, ['placed_at' => CarbonImmutable::parse('2026-10-06 02:00:00', 'Asia/Riyadh')->utc()]);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->uuid)->assertJsonPath('driver_context.work_day.date', '2026-10-06');
        $this->getJson('/api/v1/driver/orders?period=history')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $previous->uuid)->assertJsonPath('data.0.available_actions', [])->assertJsonMissingPath('data.0.customer');
        $this->getJson('/api/v1/driver/orders?period=history&work_date=2026-10-05')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/driver/orders?period=history&work_date=2026-10-04')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->url($previous, '/accept'))->assertConflict();
        $this->postJson($this->url($current, '/accept'))->assertOk();
        $this->assertNull($previous->fresh()->driver_id);
        $this->getJson('/api/v1/driver/orders?period=history&work_date=2026-10-06')->assertUnprocessable()->assertJsonValidationErrors('work_date');
        $this->getJson('/api/v1/driver/orders?period=history&work_date=not-a-date')->assertUnprocessable();
    }

    public function test_previous_active_order_stays_accessible_outside_current_day_list(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 15:00', 'Asia/Riyadh'));
        $driver = $this->driver();
        $old = $this->order($driver, ['placed_at' => now()->subDays(2), 'driver_id' => $driver->id, 'delivery_status' => 'assigned']);
        $completed = $this->order($driver, ['placed_at' => now()->subDays(2), 'driver_id' => $driver->id, 'delivery_status' => 'delivered', 'status' => 'completed']);
        $this->actingAs($driver)->getJson('/api/v1/driver/orders?scope=mine')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('driver_context.active_order.id', $old->uuid)->assertJsonPath('driver_context.active_order.is_previous_day', true);
        $history = $this->getJson('/api/v1/driver/orders?scope=mine&period=history')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$old->uuid, $completed->uuid], array_column($history->json('data'), 'id'));
        $this->getJson($this->url($old))->assertOk();
        $this->postJson($this->url($old, '/transition'), ['status' => 'picked_up'])->assertOk();
    }

    public function test_accept_checks_real_preparation_eligibility(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => OrderStatus::Confirmed]);
        $this->actingAs($driver)->postJson($this->url($order, '/accept'))->assertConflict();
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_cannot_pick_up_until_kitchen_marks_ready(): void
    {
        $this->freezeTime();
        $driver = $this->driver();
        $order = $this->order($driver, ['status' => OrderStatus::Preparing, 'estimated_ready_at' => now()->addMinutes(5),
            'driver_id' => $driver->id, 'delivery_status' => DeliveryStatus::Assigned]);
        $this->actingAs($driver)->postJson($this->url($order, '/transition'), ['status' => 'picked_up'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(DeliveryStatus::Assigned, $order->fresh()->delivery_status);
    }

    public function test_full_workflow_reuses_payment_and_tracks_unsettled_cash(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $payment = Payment::factory()->cash()->create(['order_id' => $order->id, 'amount' => '85.00']);
        $this->actingAs($driver)->postJson($this->url($order, '/accept'))->assertOk();
        $this->postJson($this->url($order, '/transition'), ['status' => 'picked_up'])->assertOk();
        $this->postJson($this->url($order, '/transition'), ['status' => 'on_the_way'])->assertOk();
        $this->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertUnprocessable();
        $this->postJson($this->url($order, '/cash/collect'), ['amount' => '84.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $collection = $this->postJson($this->url($order, '/cash/collect'), ['amount' => '85.00'])->assertOk()->json('data.id');
        $this->postJson($this->url($order, '/cash/collect'), ['amount' => '85.00'])->assertConflict();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseHas('driver_cash_collections', ['uuid' => $collection, 'driver_id' => $driver->id, 'amount' => 85, 'settlement_id' => null]);
        $this->getJson('/api/v1/driver/collections?settled=0')->assertJsonCount(1, 'data');
        $this->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertOk()->assertJsonPath('data.delivery_status', 'delivered');
        $this->postJson($this->url($order, '/transition'), ['status' => 'delivered'])->assertConflict();
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivery_delivered_at);
        $this->assertNull($order->fresh()->completed_at);
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.available_actions', []);
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier)->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->assertJsonFragment(['available_actions' => ['complete']]);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertConflict();
        $this->assertDatabaseCount('order_delivery_histories', 4);
    }

    #[DataProvider('invalidTransitions')]
    public function test_cannot_skip_or_repeat_delivery_stages(string $from, string $target): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => $from]);
        $this->actingAs($driver)->postJson($this->url($order, '/transition'), ['status' => $target, 'reason' => 'سبب الاختبار'])->assertConflict();
        $this->assertSame($from, $order->fresh()->delivery_status->value);
        $this->assertDatabaseCount('order_delivery_histories', 0);
    }

    public static function invalidTransitions(): array
    {
        $result = [];
        foreach (['assigned', 'picked_up', 'on_the_way', 'delivered', 'failed'] as $from) {
            $allowed = match ($from) {
                'assigned' => ['picked_up', 'failed'], 'picked_up' => ['on_the_way', 'failed'], 'on_the_way' => ['delivered', 'failed'], default => []
            };
            foreach (['assigned', 'picked_up', 'on_the_way', 'delivered', 'failed'] as $target) {
                if (! in_array($target, $allowed, true)) {
                    $result[$from.' to '.$target] = [$from, $target];
                }
            }
        }

        return $result;
    }

    public function test_failure_requires_reason_and_keeps_order_in_custody(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver);
        $this->actingAs($driver)->postJson($this->url($order, '/accept'));
        $this->postJson($this->url($order, '/transition'), ['status' => 'failed'])->assertUnprocessable();
        $this->postJson($this->url($order, '/transition'), ['status' => 'failed', 'reason' => 'العميل لا يرد'])->assertOk();
        $next = $this->order($driver);
        $this->postJson($this->url($next, '/accept'))->assertConflict();
        $this->assertDatabaseHas('order_delivery_histories', ['order_id' => $order->id, 'to_status' => 'failed', 'reason' => 'العميل لا يرد']);
    }

    public function test_reassignment_is_audited_and_revokes_previous_driver_access(): void
    {
        $old = $this->driver();
        $new = $this->driver($old->driverBranch);
        $order = $this->order($old, ['driver_id' => $old->id, 'delivery_status' => 'assigned']);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.$order->uuid.'/reassign-driver', ['driver_uuid' => $new->uuid, 'reason' => 'تغيير المناوبة'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'driver_id' => $new->id]);
        $this->assertDatabaseHas('order_delivery_histories', ['previous_driver_id' => $old->id, 'driver_id' => $new->id, 'changed_by' => $admin->id, 'reason' => 'تغيير المناوبة']);
        $this->actingAs($old)->getJson($this->url($order))->assertNotFound();
        $this->postJson($this->url($order, '/transition'), ['status' => 'picked_up'])->assertNotFound();
        $this->actingAs($new)->getJson($this->url($order))->assertOk();
    }

    public function test_reassignment_rejects_wrong_branch_busy_driver_and_missing_permission(): void
    {
        $old = $this->driver();
        $other = $this->driver();
        $busy = $this->driver($old->driverBranch);
        $order = $this->order($old, ['driver_id' => $old->id, 'delivery_status' => 'assigned']);
        $this->order($busy, ['driver_id' => $busy->id, 'delivery_status' => 'assigned']);
        $url = '/api/v1/admin/orders/'.$order->uuid.'/reassign-driver';
        $this->actingAs($old)->postJson($url, ['driver_uuid' => $busy->uuid, 'reason' => 'تغيير المناوبة'])->assertForbidden();
        $this->actingAs($this->admin())->postJson($url, ['driver_uuid' => $other->uuid, 'reason' => 'تغيير المناوبة'])->assertUnprocessable();
        $this->postJson($url, ['driver_uuid' => $busy->uuid, 'reason' => 'تغيير المناوبة'])->assertConflict();
        $this->assertSame($old->id, $order->fresh()->driver_id);
    }

    public function test_management_creates_driver_and_blocks_branch_changes_with_active_order(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        $payload = ['name' => 'مندوب جديد', 'phone' => '+966551234999', 'password' => 'DriverPassword123', 'branch_uuid' => $branch->uuid, 'enabled' => true, 'available' => true];
        $id = $this->actingAs($admin)->postJson('/api/v1/admin/drivers', $payload)->assertCreated()->json('data.id');
        $driver = User::where('uuid', $id)->sole();
        $this->assertTrue($driver->hasRole('driver'));
        $this->assertFalse(app(AdminAccessService::class)->allowed($driver));
        $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'assigned']);
        unset($payload['password']);
        $payload['branch_uuid'] = Branch::factory()->create()->uuid;
        $this->putJson('/api/v1/admin/drivers/'.$id, $payload)->assertConflict();
        $this->assertSame($branch->id, $driver->fresh()->driver_branch_id);
        $payload['branch_uuid'] = $branch->uuid;
        $payload['enabled'] = false;
        $this->putJson('/api/v1/admin/drivers/'.$id, $payload)->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_settlement_requires_exact_amount_and_cannot_repeat_or_use_another_drivers_cash(): void
    {
        $driver = $this->driver();
        $other = $this->driver();
        $order = $this->order($driver);
        $payment = Payment::factory()->cash()->create(['order_id' => $order->id, 'amount' => '85.00']);
        $collection = DriverCashCollection::create(['driver_id' => $driver->id, 'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => '85.00', 'currency' => 'SAR', 'collected_at' => now()]);
        $payload = ['collection_uuids' => [$collection->uuid], 'amount' => '85.00', 'reason' => 'استلام العهدة نقدًا'];
        $url = '/api/v1/admin/drivers/'.$driver->uuid.'/settlements';
        $this->actingAs($driver)->postJson($url, $payload)->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->postJson($url, array_merge($payload, ['amount' => '80.00']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/drivers/'.$other->uuid.'/settlements', $payload)->assertConflict();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.amount', '85.00');
        $this->postJson($url, $payload)->assertConflict();
        $this->assertNotNull($collection->fresh()->settlement_id);
        $this->assertDatabaseCount('driver_cash_settlements', 1);
        $this->assertDatabaseHas('driver_cash_settlements', ['cashier_id' => $admin->id, 'driver_id' => $driver->id, 'amount' => 85]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'drivers.settle_cash']);
    }

    public function test_other_driver_cannot_collect_or_transition_and_coordinates_come_from_order_snapshot(): void
    {
        $owner = $this->driver();
        $other = $this->driver($owner->driverBranch);
        $order = $this->order($owner, ['driver_id' => $owner->id, 'delivery_status' => 'picked_up']);
        $order->address->update(['latitude' => '28.4378538', 'longitude' => '36.5455267']);
        $this->actingAs($other)->postJson($this->url($order, '/cash/collect'), ['amount' => '85.00'])->assertNotFound();
        $this->postJson($this->url($order, '/transition'), ['status' => 'on_the_way'])->assertNotFound();
        $this->actingAs($owner)->getJson($this->url($order))->assertOk()->assertJsonPath('data.address.location.latitude', '28.4378538');
    }

    public function test_driver_login_uses_sanctum_without_granting_admin_access(): void
    {
        $driver = $this->driver();
        $driver->forceFill(['password' => 'DriverPassword123'])->save();
        $token = $this->postJson('/api/v1/login', ['email' => $driver->email, 'password' => 'DriverPassword123'])
            ->assertOk()->assertJsonPath('data.user.is_driver', true)->json('data.token');
        $this->withToken($token)->getJson('/api/v1/driver/me')->assertOk()->assertJsonPath('data.id', $driver->uuid);
        $this->getJson('/api/v1/admin/drivers')->assertForbidden();
    }

    public function test_cash_collection_rejects_non_cash_orders_and_cancelled_orders(): void
    {
        $driver = $this->driver();
        $network = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'picked_up', 'payment_method' => 'network']);
        $cancelled = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'picked_up', 'status' => 'cancelled']);
        $this->actingAs($driver)->postJson($this->url($network, '/cash/collect'), ['amount' => '85.00'])->assertConflict();
        $this->postJson($this->url($cancelled, '/cash/collect'), ['amount' => '85.00'])->assertConflict();
        $this->assertDatabaseCount('driver_cash_collections', 0);
    }

    public function test_admin_order_actions_preserve_driver_workflow_and_include_delivery_history(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'assigned']);
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()
            ->assertJsonPath('data.delivery.driver.id', $driver->uuid)->assertJsonPath('data.delivery.status', 'assigned');
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/dispatch')->assertConflict();
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'إلغاء من الإدارة'])->assertOk();
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertDatabaseHas('order_delivery_histories', ['order_id' => $order->id, 'changed_by' => $admin->id, 'reason' => 'إلغاء من الإدارة']);
    }

    public function test_admin_can_retry_failed_delivery_with_same_driver_without_resetting_pickup(): void
    {
        $driver = $this->driver();
        $order = $this->order($driver, ['driver_id' => $driver->id, 'delivery_status' => 'failed', 'delivery_picked_up_at' => now()]);
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$order->uuid.'/reassign-driver',
            ['driver_uuid' => $driver->uuid, 'reason' => 'إعادة المحاولة بعد التواصل'])->assertOk()->assertJsonPath('data.delivery_status', 'picked_up');
        $this->assertSame(DeliveryStatus::PickedUp, $order->fresh()->delivery_status);
    }

    public function test_cashier_can_settle_cash_but_cannot_reassign_or_manage_drivers(): void
    {
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier)->getJson('/api/v1/admin/drivers')->assertOk();
        $this->postJson('/api/v1/admin/drivers', [])->assertForbidden();
        $driver = $this->driver();
        $order = $this->order($driver);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/reassign-driver', ['driver_uuid' => $driver->uuid, 'reason' => 'تغيير المندوب'])->assertForbidden();
        $this->assertTrue($cashier->can('drivers.settle_cash'));
    }

    public function test_reassignment_keeps_collected_cash_with_original_driver_until_cashier_settles_it(): void
    {
        $original = $this->driver();
        $replacement = $this->driver($original->driverBranch);
        $order = $this->order($original, ['driver_id' => $original->id, 'delivery_status' => 'on_the_way', 'status' => 'out_for_delivery']);
        Payment::factory()->cash()->create(['order_id' => $order->id, 'amount' => '85.00']);
        $collection = $this->actingAs($original)->postJson($this->url($order, '/cash/collect'), ['amount' => '85.00'])
            ->assertOk()->json('data.id');
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$order->uuid.'/reassign-driver',
            ['driver_uuid' => $replacement->uuid, 'reason' => 'نقل الطلب بعد التحصيل'])->assertOk();
        $this->actingAs($replacement)->getJson($this->url($order))->assertOk()->assertJsonPath('data.cash_due', '0.00');
        $this->getJson('/api/v1/driver/collections')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('driver_cash_collections', ['uuid' => $collection, 'driver_id' => $original->id, 'settlement_id' => null]);
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->actingAs($cashier)->postJson('/api/v1/admin/drivers/'.$original->uuid.'/settlements',
            ['collection_uuids' => [$collection], 'amount' => '85.00', 'reason' => 'استلام المبلغ من المندوب الأصلي'])->assertOk();
        $this->assertDatabaseHas('driver_cash_settlements', ['driver_id' => $original->id, 'cashier_id' => $cashier->id, 'amount' => 85]);
    }
}
