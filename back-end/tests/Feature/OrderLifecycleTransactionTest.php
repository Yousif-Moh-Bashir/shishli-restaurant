<?php

namespace Tests\Feature;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderLifecycleTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_status_event_waits_for_outer_commit_and_contains_audit_data(): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create();
        $events = [];
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event) use (&$events): void {
            $events[] = [$event->order->id, $event->fromStatus, $event->toStatus, $event->actorId, $event->source, DB::transactionLevel()];
        });
        DB::beginTransaction();
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
        $this->assertSame([], $events);
        DB::commit();
        $this->assertSame([[$order->id, OrderStatus::Pending, OrderStatus::Confirmed, $actor->id, OrderStatusSource::Admin, 0]], $events);
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_rolled_back_transition_does_not_dispatch_event_or_leave_history(): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create();
        $events = [];
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event) use (&$events): void {
            $events[] = $event;
        });
        DB::beginTransaction();
        app(TransitionOrderStatusAction::class)->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
        DB::rollBack();
        $this->assertSame([], $events);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_at);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    #[DataProvider('operations')]
    public function test_history_persistence_failure_rolls_back_all_transition_fields(string $command): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create();
        Event::fake([OrderStatusChanged::class]);
        $event = 'eloquent.creating: '.OrderStatusHistory::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated history persistence failure');
        });
        try {
            $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/'.$command, ['reason' => 'Cancel request'])->assertServerError();
        } finally {
            Event::forget($event);
        }
        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->confirmed_at);
        $this->assertNull($order->cancelled_at);
        $this->assertNull($order->cancelled_by);
        $this->assertNull($order->cancellation_reason);
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public static function operations(): array
    {
        return [['confirm'], ['cancel']];
    }

    #[DataProvider('raceWinners')]
    public function test_stale_ready_or_cancel_operation_is_rejected_after_competing_transition(bool $cancelFirst): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create(['status' => 'preparing']);
        $stale = $order->fresh();
        $transition = app(TransitionOrderStatusAction::class);
        $cancel = app(CancelOrderAction::class);
        if ($cancelFirst) {
            $cancel->handle($order, 'Cancel request', $actor, OrderStatusSource::Admin);
        } else {
            $transition->handle($order, OrderStatus::Ready, $actor, OrderStatusSource::Admin);
        }
        Event::fake([OrderStatusChanged::class]);
        try {
            if ($cancelFirst) {
                $transition->handle($stale, OrderStatus::Ready, $actor, OrderStatusSource::Admin);
            } else {
                $cancel->handle($stale, 'Cancel request', $actor, OrderStatusSource::Admin);
            }
            $this->fail('A stale operation must not overwrite a competing transition.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($cancelFirst ? OrderStatus::Cancelled : OrderStatus::Ready, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 1);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public static function raceWinners(): array
    {
        return [[true], [false]];
    }

    public function test_two_confirms_from_same_snapshot_create_only_one_history(): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create();
        $stale = $order->fresh();
        $transition = app(TransitionOrderStatusAction::class);
        $transition->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
        try {
            $transition->handle($stale, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
            $this->fail('Duplicate confirmation must conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    #[DataProvider('historyMutations')]
    public function test_history_records_cannot_be_changed_or_deleted(string $operation): void
    {
        $history = OrderStatusHistory::factory()->create();
        try {
            if ($operation === 'update') {
                $history->update(['note' => 'Modified']);
            } else {
                $history->delete();
            }
            $this->fail('Order history must remain append-only.');
        } catch (\LogicException $exception) {
            $this->assertSame('Order history is append-only.', $exception->getMessage());
        }
        $this->assertModelExists($history);
        $this->assertNull($history->fresh()->note);
    }

    public static function historyMutations(): array
    {
        return [['update'], ['delete']];
    }

    public function test_history_order_uses_id_tie_breaker_and_survives_actor_deletion(): void
    {
        $this->freezeTime();
        $actor = $this->manager();
        $order = Order::factory()->create();
        $transition = app(TransitionOrderStatusAction::class);
        $confirmed = $transition->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::Admin);
        $transition->handle($confirmed, OrderStatus::Preparing, $actor, OrderStatusSource::Admin);
        $actor->delete();
        $viewer = User::factory()->create()->givePermissionTo('orders.view');
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/orders/'.$order->uuid.'/history')->assertOk()
            ->assertJsonPath('data.0.to_status', 'confirmed')->assertJsonPath('data.1.to_status', 'preparing')
            ->assertJsonPath('data.0.changed_by', null)->assertJsonPath('data.1.changed_by', null);
        $this->assertDatabaseCount('order_status_histories', 2);
    }

    public function test_generic_transition_cannot_cancel_without_reason_or_spoof_source(): void
    {
        $actor = $this->manager();
        $order = Order::factory()->create();
        $transition = app(TransitionOrderStatusAction::class);
        try {
            $transition->handle($order, OrderStatus::Cancelled, $actor, OrderStatusSource::Admin);
            $this->fail('Cancellation must require a reason on every path.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        try {
            $transition->handle($order, OrderStatus::Confirmed, $actor, OrderStatusSource::System);
            $this->fail('System source must not bypass actor attribution.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    private function manager(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('manager');
    }
}
