<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cancellableStatuses')]
    public function test_admin_cancellation_saves_reason_actor_timestamp_and_history_without_refund(string $status): void
    {
        $this->freezeTime();
        $manager = $this->manager();
        $order = Order::factory()->create(['status' => $status, 'payment_status' => 'paid']);
        Event::fake([OrderStatusChanged::class]);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', [
            'reason' => 'Customer requested', 'cancelled_by' => 999, 'cancelled_at' => '2000-01-01',
            'payment_status' => 'refunded', 'status' => 'completed', 'source' => 'customer',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation.reason', 'Customer requested')
            ->assertJsonPath('data.cancellation.cancelled_at', now()->toIso8601String())->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonMissingPath('data.cancelled_by');
        $order->refresh();
        $this->assertSame($manager->id, $order->cancelled_by);
        $this->assertSame('Customer requested', $order->cancellation_reason);
        $history = $order->statusHistory()->sole();
        $this->assertSame($status, $history->from_status->value);
        $this->assertSame(OrderStatus::Cancelled, $history->to_status);
        $this->assertSame(OrderStatusSource::Admin, $history->source);
        $this->assertSame($manager->id, $history->changed_by);
        $this->assertSame('Customer requested', $history->note);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->order->id === $order->id && $event->fromStatus->value === $status && $event->toStatus === OrderStatus::Cancelled
            && $event->actorId === $manager->id && $event->source === OrderStatusSource::Admin);
    }

    public static function cancellableStatuses(): array
    {
        return [['pending'], ['confirmed'], ['preparing'], ['ready']];
    }

    #[DataProvider('nonCancellableStatuses')]
    public function test_admin_cannot_cancel_dispatched_completed_or_cancelled_orders(string $status): void
    {
        $manager = $this->manager();
        $order = Order::factory()->create(['type' => 'delivery', 'status' => $status]);
        Event::fake([OrderStatusChanged::class]);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'Cancel request'])
            ->assertUnprocessable();
        $this->assertSame($status, $order->fresh()->status->value);
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public static function nonCancellableStatuses(): array
    {
        return [['out_for_delivery'], ['completed'], ['cancelled']];
    }

    #[DataProvider('invalidReasons')]
    public function test_cancellation_requires_valid_reason(mixed $reason): void
    {
        $manager = $this->manager();
        $order = Order::factory()->create();
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => $reason])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public static function invalidReasons(): array
    {
        return [[null], [''], ['  '], ['ab'], [str_repeat('a', 501)], [['invalid']]];
    }

    public function test_customer_can_cancel_own_pending_order_and_read_current_state(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $this->postJson('/api/v1/orders/'.$order->uuid.'/cancel', ['reason' => 'Changed mind'])->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders/'.$order->uuid.'/cancel', ['reason' => 'Changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame($user->id, $order->fresh()->cancelled_by);
        $history = $order->statusHistory()->sole();
        $this->assertSame(OrderStatusSource::Customer, $history->source);
        $this->assertSame($user->id, $history->changed_by);
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.cancellation.reason', 'Changed mind');
    }

    #[DataProvider('customerStatuses')]
    public function test_customer_cannot_cancel_after_confirmation_even_if_staff(string $status): void
    {
        $user = $this->manager();
        $order = Order::factory()->for($user)->create(['type' => 'delivery', 'status' => $status]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders/'.$order->uuid.'/cancel', [
            'reason' => 'Changed mind', 'source' => 'admin',
        ])->assertUnprocessable();
        $this->assertSame($status, $order->fresh()->status->value);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public static function customerStatuses(): array
    {
        return [['confirmed'], ['preparing'], ['ready'], ['out_for_delivery'], ['completed'], ['cancelled']];
    }

    public function test_customer_cannot_cancel_another_users_or_guest_order(): void
    {
        $user = User::factory()->create();
        $foreign = Order::factory()->for(User::factory())->create();
        $guest = Order::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders/'.$foreign->uuid.'/cancel', ['reason' => 'Changed mind'])->assertNotFound();
        $this->postJson('/api/v1/orders/'.$guest->uuid.'/cancel', ['reason' => 'Changed mind'])->assertNotFound();
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_guest_cancel_requires_matching_token_and_records_customer_source(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['access_token' => str_repeat('a', 64)])->save();
        $other = Order::factory()->create();
        $other->forceFill(['access_token' => str_repeat('b', 64)])->save();
        $path = '/api/v1/orders/'.$order->uuid.'/guest/cancel';
        $this->postJson($path, ['reason' => 'Changed mind'])->assertNotFound();
        $this->withHeader('X-Order-Token', str_repeat('b', 64))->postJson($path, ['reason' => 'Changed mind'])->assertNotFound();
        $this->withHeader('X-Order-Token', str_repeat('a', 64))->postJson($path, ['reason' => 'Changed mind'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonMissingPath('data.access_token');
        $history = $order->statusHistory()->sole();
        $this->assertSame(OrderStatusSource::Customer, $history->source);
        $this->assertNull($history->changed_by);
        $this->assertNull($order->fresh()->cancelled_by);
        $this->postJson('/api/v1/orders/'.$other->uuid.'/guest/cancel', ['reason' => 'Changed mind'])->assertNotFound();
    }

    public function test_guest_cannot_cancel_confirmed_or_authenticated_orders(): void
    {
        $order = Order::factory()->confirmed()->create();
        $order->forceFill(['access_token' => str_repeat('a', 64)])->save();
        $this->withHeader('X-Order-Token', str_repeat('a', 64))
            ->postJson('/api/v1/orders/'.$order->uuid.'/guest/cancel', ['reason' => 'Changed mind'])->assertUnprocessable();
        $auth = Order::factory()->for(User::factory())->create();
        $this->postJson('/api/v1/orders/'.$auth->uuid.'/guest/cancel', ['reason' => 'Changed mind'])->assertNotFound();
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    private function manager(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('manager');
    }
}
