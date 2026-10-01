<?php

namespace Tests\Feature;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\BranchProduct;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('orderTypes')]
    public function test_checkout_to_completion_creates_ordered_history_and_timeline(string $type): void
    {
        $this->freezeTime();
        $manager = $this->staff('manager');
        $assignment = BranchProduct::factory()->create(['price_override' => '25.00']);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 2])->assertCreated();
        $payload = ['type' => $type, 'customer' => ['name' => 'Customer', 'phone' => '0501234567'], 'payment_method' => 'cash'];
        if ($type === 'delivery') {
            DeliveryZone::factory()->for($assignment->branch)->create();
            $payload['address'] = ['recipient_name' => 'Customer', 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف'];
        }
        $checkout = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.status', 'pending');
        $order = Order::where('uuid', $checkout->json('data.id'))->firstOrFail();
        $initial = $order->statusHistory()->sole();
        $this->assertNull($initial->from_status);
        $this->assertSame(OrderStatus::Pending, $initial->to_status);
        $this->assertSame(OrderStatusSource::System, $initial->source);
        $this->assertNull($initial->changed_by);
        $this->postJson('/api/v1/checkout', $payload)->assertOk();
        $this->assertSame(1, $order->statusHistory()->count());
        $this->actingAs($manager, 'sanctum');
        $commands = ['confirm' => 'confirmed', 'start-preparing' => 'preparing', 'mark-ready' => 'ready'];
        if ($type === 'delivery') {
            $commands['dispatch'] = 'out_for_delivery';
        }
        $commands['complete'] = 'completed';
        Event::fake([OrderStatusChanged::class]);
        $previous = 'pending';
        foreach ($commands as $command => $status) {
            $this->travel(1)->minutes();
            $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/'.$command, ['note' => 'Operation note'])->assertOk()
                ->assertJsonPath('data.status', $status)->assertJsonPath('data.timeline.'.$status.'_at', now()->toIso8601String())
                ->assertJsonPath('data.payment.status', 'pending');
            $history = $order->statusHistory()->get()->last();
            $this->assertSame($previous, $history->from_status->value);
            $this->assertSame($status, $history->to_status->value);
            $this->assertSame($manager->id, $history->changed_by);
            $this->assertSame(OrderStatusSource::Admin, $history->source);
            $this->assertSame('Operation note', $history->note);
            $this->assertNull($history->metadata);
            $previous = $status;
        }
        Event::assertDispatchedTimes(OrderStatusChanged::class, count($commands));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        if ($type === 'pickup') {
            $this->assertNull($order->fresh()->out_for_delivery_at);
        }
        $this->getJson('/api/v1/admin/orders/'.$order->uuid.'/history')->assertOk()
            ->assertJsonCount(count($commands) + 1, 'data')->assertJsonPath('data.0.from_status', null)
            ->assertJsonPath('data.0.changed_by', null)->assertJsonPath('data.1.changed_by.id', $manager->uuid)
            ->assertJsonPath('data.1.source', 'admin')->assertJsonMissingPath('data.1.metadata');
        $this->withHeader('X-Order-Token', $checkout->json('data.access_token'))
            ->getJson('/api/v1/orders/'.$order->uuid.'/guest')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public static function orderTypes(): array
    {
        return [['pickup'], ['delivery']];
    }

    #[DataProvider('commands')]
    public function test_each_admin_command_requires_its_specific_permission(string $command, string $from, string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $order = Order::factory()->create(['type' => 'delivery', 'status' => $from]);
        $url = '/api/v1/admin/orders/'.$order->uuid.'/'.$command;
        $this->postJson($url, ['reason' => 'Customer requested'])->assertUnauthorized();
        $user = User::factory()->create()->givePermissionTo('orders.view');
        $this->actingAs($user, 'sanctum')->postJson($url, ['reason' => 'Customer requested'])->assertForbidden();
        $this->assertDatabaseCount('order_status_histories', 0);
        $user->givePermissionTo($permission);
        $this->postJson($url, ['reason' => 'Customer requested'])->assertOk();
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public static function commands(): array
    {
        return [['confirm', 'pending', 'orders.confirm'], ['start-preparing', 'confirmed', 'orders.start_preparing'],
            ['mark-ready', 'preparing', 'orders.mark_ready'], ['dispatch', 'ready', 'orders.dispatch'],
            ['complete', 'out_for_delivery', 'orders.complete'], ['cancel', 'pending', 'orders.cancel']];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_return_422_without_events_or_history(string $type, string $from, string $command): void
    {
        $manager = $this->staff('manager');
        $order = Order::factory()->create(['type' => $type, 'status' => $from]);
        Event::fake([OrderStatusChanged::class]);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/'.$command)->assertUnprocessable();
        $this->assertSame($from, $order->fresh()->status->value);
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public static function invalidTransitions(): array
    {
        return [['pickup', 'ready', 'dispatch'], ['delivery', 'ready', 'complete'], ['pickup', 'pending', 'start-preparing'],
            ['delivery', 'pending', 'mark-ready'], ['pickup', 'confirmed', 'complete'], ['delivery', 'preparing', 'confirm'],
            ['pickup', 'ready', 'start-preparing'], ['delivery', 'completed', 'mark-ready'], ['pickup', 'cancelled', 'confirm']];
    }

    public function test_duplicate_confirm_returns_conflict_and_preserves_timestamp_and_history(): void
    {
        $manager = $this->staff('manager');
        $order = Order::factory()->create();
        $this->freezeTime();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertOk();
        $confirmed = $order->fresh()->confirmed_at->toIso8601String();
        $this->travel(1)->minutes();
        Event::fake([OrderStatusChanged::class]);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertConflict();
        $this->assertSame($confirmed, $order->fresh()->confirmed_at->toIso8601String());
        $this->assertDatabaseCount('order_status_histories', 1);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_untrusted_status_actor_source_and_timestamps_are_ignored(): void
    {
        $manager = $this->staff('manager');
        $order = Order::factory()->create();
        $this->freezeTime();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm', [
            'status' => 'completed', 'source' => 'system', 'changed_by' => 999, 'cancelled_by' => 999,
            'confirmed_at' => '2000-01-01', 'completed_at' => '2000-01-01', 'payment_status' => 'paid',
        ])->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.timeline.completed_at', null)
            ->assertJsonPath('data.timeline.confirmed_at', now()->toIso8601String())->assertJsonPath('data.payment.status', 'pending');
        $history = $order->statusHistory()->sole();
        $this->assertSame($manager->id, $history->changed_by);
        $this->assertSame(OrderStatusSource::Admin, $history->source);
    }

    public function test_history_endpoint_requires_permission_and_legacy_orders_have_empty_history(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $order = Order::factory()->create();
        $this->getJson('/api/v1/admin/orders/'.$order->uuid.'/history')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->uuid.'/history')->assertForbidden();
        $user->givePermissionTo('orders.view');
        $this->getJson('/api/v1/admin/orders/'.$order->uuid.'/history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.timeline.confirmed_at', null);
        $history = OrderStatusHistory::factory()->for($order)->create();
        $this->patchJson('/api/v1/admin/orders/'.$order->uuid.'/history/'.$history->uuid, ['note' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/v1/admin/orders/'.$order->uuid.'/history/'.$history->uuid)->assertNotFound();
        $this->assertModelExists($history);
    }

    public function test_actions_enforce_permissions_even_without_http_middleware(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $order = Order::factory()->create();
        $actor = User::factory()->create();
        try {
            app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
            $this->fail('An unprivileged actor must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    private function staff(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole($role);
    }
}
