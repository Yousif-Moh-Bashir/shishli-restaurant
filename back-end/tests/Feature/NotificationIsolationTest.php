<?php

namespace Tests\Feature;

use App\Actions\Notifications\RetryNotificationAction;
use App\Actions\Notifications\SendNotificationAction;
use App\Actions\Orders\PlaceOrderAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Events\PaymentSucceeded;
use App\Jobs\SendNotificationJob;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OutboundNotification;
use App\Models\Payment;
use App\Models\User;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationTemplateRenderer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class NotificationIsolationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbound_notifications.channels.sms' => ['enabled' => true, 'provider' => 'fake'], 'outbound_notifications.providers.sms.fake' => FakeSmsProvider::class]);
        foreach (NotificationType::cases() as $type) {
            config(['outbound_notifications.types.'.$type->value.'.sms' => true]);
        }
        $this->app->singleton(FakeSmsProvider::class);
        app(FakeSmsProvider::class)->results = [new \RuntimeException('Authorization: Bearer TOP_SECRET')];
    }

    private function cart(): Cart
    {
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '20.00']);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();

        return Cart::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    private function payload(): array
    {
        return ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Customer', 'phone' => '+966501234567']];
    }

    private function worker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0, '--timeout' => 30, '--tries' => 3])->assertExitCode(0);
    }

    public function test_checkout_remains_successful_when_queued_provider_throws(): void
    {
        $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('pending', OutboundNotification::first()->status->value);
        $this->worker();
        $this->assertSame('failed', OutboundNotification::first()->status->value);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('20.00', Order::first()->total);
        $this->assertSame(1, Order::first()->statusHistory()->count());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notification_attempts', 1);
        $this->assertStringNotContainsString('TOP_SECRET', OutboundNotification::first()->last_error_message);
    }

    public function test_status_transition_and_history_remain_successful_when_provider_throws(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create()->assignRole('manager');
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->worker();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertSame('failed', OutboundNotification::first()->status->value);
    }

    public function test_cash_payment_and_financial_transaction_remain_paid_when_provider_throws(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create()->assignRole('manager');
        $order = Order::factory()->create(['customer_phone' => '+966501234567', 'total' => '20.00', 'subtotal' => '20.00']);
        $payment = Payment::factory()->for($order)->create(['method' => 'cash', 'provider' => null, 'status' => 'pending', 'amount' => '20.00']);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/payments/cash/collect')->assertOk();
        $this->worker();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseHas('payment_transactions', ['payment_id' => $payment->id, 'status' => 'succeeded']);
        $this->assertSame('failed', OutboundNotification::first()->status->value);
    }

    public function test_enabled_channel_without_provider_does_not_roll_back_checkout(): void
    {
        config(['outbound_notifications.channels.sms.provider' => null]);
        $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->worker();
        $this->assertSame('PROVIDER_NOT_CONFIGURED', OutboundNotification::first()->last_error_code);
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    public function test_queue_insertion_failure_does_not_fail_checkout_and_releases_unique_lock_for_manual_retry(): void
    {
        $this->cart();
        Queue::shouldReceive('connection')->with('database')->andThrow(new \RuntimeException('queue credentials TOP_SECRET'));
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $notification = OutboundNotification::firstOrFail();
        $this->assertSame('QUEUE_DISPATCH_FAILED', $notification->last_error_code);
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertDatabaseCount('orders', 1);
        $lock = new UniqueLock(app(Repository::class));
        $job = new SendNotificationJob($notification->uuid);
        $this->assertTrue($lock->acquire($job));
        $lock->release($job);
        Queue::fake();
        app(RetryNotificationAction::class)->handle($notification);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_sync_connection_is_rejected_without_external_request(): void
    {
        config(['outbound_notifications.connection' => 'sync']);
        $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->assertSame('QUEUE_DISPATCH_FAILED', OutboundNotification::first()->last_error_code);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_template_failure_never_escapes_checkout(): void
    {
        $this->mock(NotificationTemplateRenderer::class, fn ($mock) => $mock->shouldReceive('render')->andThrow(new \RuntimeException('template failure')));
        $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbound_notifications', 0);
    }

    public function test_after_commit_event_does_not_create_or_dispatch_before_commit(): void
    {
        Queue::fake();
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        DB::beginTransaction();
        OrderPlaced::dispatch($order);
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
        DB::commit();
        $this->assertDatabaseCount('outbound_notifications', 1);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_rollback_discards_order_event_and_notification(): void
    {
        Queue::fake();
        $cart = $this->cart();
        DB::beginTransaction();
        app(PlaceOrderAction::class)->handle($cart, $this->payload());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_direct_service_dispatch_is_also_deferred_and_rolled_back(): void
    {
        Queue::fake();
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        DB::beginTransaction();
        app(NotificationService::class)->queueNotification(NotificationType::OrderPlaced, NotificationContext::fromOrder($order));
        $this->assertDatabaseCount('outbound_notifications', 1);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_payment_event_waits_for_commit_and_rollback_creates_no_notification(): void
    {
        Queue::fake();
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        $payment = Payment::factory()->for($order)->create();
        DB::beginTransaction();
        PaymentSucceeded::dispatch($payment, $order);
        $this->assertDatabaseCount('outbound_notifications', 0);
        DB::rollBack();
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_provider_execution_happens_outside_database_transaction(): void
    {
        $notification = OutboundNotification::factory()->create();
        app(FakeSmsProvider::class)->results = [];
        app(FakeSmsProvider::class)->beforeSend = function (): void {
            $this->assertSame(0, DB::transactionLevel());
        };
        app(SendNotificationAction::class)->handle($notification->uuid);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
    }
}
