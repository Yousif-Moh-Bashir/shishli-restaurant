<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Jobs\SendStaffOrderNotification;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffNotificationDelivery;
use App\Models\StaffPushSubscription;
use App\Models\User;
use App\Services\Notifications\StaffNotificationSender;
use App\Services\Notifications\StaffOrderNotifications;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

class StaffOrderNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    private function enable(): void
    {
        config([
            'staff_notifications.enabled' => true,
            'staff_notifications.starts_at' => now()->subMinute()->toISOString(),
            'staff_notifications.connection' => 'database',
            'staff_notifications.push.enabled' => true,
            'staff_notifications.sound.enabled' => true,
            'staff_notifications.telegram.enabled' => true,
            'staff_notifications.telegram.token' => 'test-secret',
            'staff_notifications.telegram.chat_id' => '-100123',
        ]);
    }

    private function staff(string $role = 'cashier'): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole($role);
    }

    private function signIn(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function subscription(User $user): StaffPushSubscription
    {
        return StaffPushSubscription::factory()->create(['user_id' => $user->id]);
    }

    private function capture(?Order $order = null): Order
    {
        $order ??= Order::factory()->create();
        OrderPlaced::dispatch($order);

        return $order;
    }

    private function work(StaffNotificationDelivery $delivery): void
    {
        (new SendStaffOrderNotification($delivery->id))->handle(app(StaffOrderNotifications::class), app(StaffNotificationSender::class));
    }

    public function test_disabled_by_default_and_missing_activation_never_capture_orders(): void
    {
        Queue::fake();
        $this->capture();
        $this->assertDatabaseCount('staff_order_notifications', 0);
        config(['staff_notifications.enabled' => true]);
        $this->capture();
        $this->assertDatabaseCount('staff_order_notifications', 0);
        config(['staff_notifications.starts_at' => 'tomorrow']);
        $this->capture();
        $this->assertDatabaseCount('staff_order_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_old_orders_and_replayed_events_do_not_create_duplicate_deliveries(): void
    {
        $this->enable();
        Queue::fake();
        $this->capture(Order::factory()->create(['placed_at' => now()->subDays(2)]));
        $order = $this->capture();
        $this->capture($order);
        $this->assertDatabaseCount('staff_order_notifications', 1);
        $this->assertDatabaseCount('staff_notification_deliveries', 1);
        $this->assertDatabaseHas('staff_order_notifications', ['order_id' => $order->id, 'branch_id' => $order->branch_id]);
        Queue::assertPushed(SendStaffOrderNotification::class, 1);
    }

    public function test_only_active_users_with_actual_admin_order_access_receive_push(): void
    {
        $this->enable();
        Queue::fake();
        $cashier = $this->staff();
        $manager = $this->staff('manager');
        $kitchen = $this->staff('kitchen');
        $customer = $this->staff('customer');
        $driver = $this->staff('driver');
        $inactive = $this->staff();
        $inactive->forceFill(['is_active' => false])->save();
        foreach ([$cashier, $manager, $kitchen, $customer, $driver, $inactive] as $user) {
            $this->subscription($user);
        }
        $this->capture();
        $this->assertSame(3, StaffNotificationDelivery::where('channel', 'push')->count());
        $driver->givePermissionTo('orders.view');
        $customer->givePermissionTo('orders.view');
        $this->capture();
        // Driver now has administrative access under the existing AdminAccessService.
        // A customer without a staff role remains excluded by that same service.
        $this->assertSame(7, StaffNotificationDelivery::where('channel', 'push')->count());
    }

    public function test_authentication_permission_and_subscription_ownership_are_enforced(): void
    {
        $this->enable();
        $this->getJson('/api/v1/admin/staff-notifications')->assertUnauthorized();
        $this->signIn($this->staff('customer'));
        $this->getJson('/api/v1/admin/staff-notifications')->assertForbidden();
        $this->signIn($this->staff('driver'));
        $this->getJson('/api/v1/admin/staff-notifications')->assertForbidden();
        $owner = $this->staff();
        $subscription = $this->subscription($owner);
        $this->signIn($this->staff());
        $this->deleteJson('/api/v1/admin/staff-notifications/subscriptions/'.$subscription->uuid)->assertNotFound();
        $this->assertDatabaseHas('staff_push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_subscription_validation_blocks_ssrf_and_encrypts_browser_keys(): void
    {
        $this->enable();
        $user = $this->staff();
        $this->signIn($user);
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/example', 'keys' => ['p256dh' => 'B'.str_repeat('a', 86), 'auth' => str_repeat('a', 22)]];
        foreach (['http://127.0.0.1/x', 'https://localhost/x', 'https://fcm.googleapis.com.evil.test/x', 'https://user:pass@fcm.googleapis.com/x', 'https://fcm.googleapis.com:444/x'] as $endpoint) {
            $this->postJson('/api/v1/admin/staff-notifications/subscriptions', ['endpoint' => $endpoint] + $payload)->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        }
        $id = $this->postJson('/api/v1/admin/staff-notifications/subscriptions', $payload)->assertOk()->json('data.id');
        $this->postJson('/api/v1/admin/staff-notifications/subscriptions', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('staff_push_subscriptions', 1);
        $raw = DB::table('staff_push_subscriptions')->value('subscription');
        $this->assertStringNotContainsString($payload['endpoint'], $raw);
        $this->signIn($this->staff());
        $this->postJson('/api/v1/admin/staff-notifications/subscriptions', $payload)->assertConflict();
    }

    public function test_confirmed_orders_disappear_from_feed_and_cancel_queued_delivery(): void
    {
        $this->enable();
        Queue::fake();
        $user = $this->staff();
        $this->signIn($user);
        $order = $this->capture();
        $this->getJson('/api/v1/admin/staff-notifications')->assertOk()->assertJsonCount(1, 'data.pending');
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertOk();
        $this->getJson('/api/v1/admin/staff-notifications')->assertOk()->assertJsonCount(0, 'data.pending');
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->work($delivery);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_telegram_message_is_sent_once_without_customer_secrets(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $order = $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->work($delivery);
        $this->work($delivery);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-100123'
            && $request['allow_paid_broadcast'] === false
            && str_contains($request['text'], '/admin/orders/'.$order->uuid)
            && ! str_contains($request['text'], $order->customer_phone)
            && ! str_contains($request['text'], $order->customer_email));
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_rate_limit_is_retried_only_after_provider_delay(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 90]], 429)
            ->push(['ok' => true])]);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->work($delivery);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->work($delivery);
        Http::assertSentCount(1);
        $this->travel(91)->seconds();
        $this->work($delivery);
        Http::assertSentCount(2);
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_uncertain_transport_failure_is_not_automatically_resent_or_logged_with_secrets(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::failedConnection('TOP_SECRET test-secret')]);
        $order = $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        Log::spy();
        $this->work($delivery);
        $this->work($delivery);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertSame('transport_uncertain', $delivery->fresh()->error_code);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Log::shouldHaveReceived('warning')->once()->with('staff_notifications.delivery_failed', [
            'delivery_id' => $delivery->id, 'channel' => 'telegram', 'status' => 'uncertain',
            'error_code' => 'transport_uncertain', 'attempts' => 1,
        ]);
    }

    public function test_kill_switch_reactivation_boundary_and_expiry_prevent_backlog_delivery(): void
    {
        $this->enable();
        Queue::fake();
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        config(['staff_notifications.telegram.enabled' => false]);
        $this->work($delivery);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        config(['staff_notifications.telegram.enabled' => true]);
        $this->capture();
        $next = StaffNotificationDelivery::latest('id')->firstOrFail();
        config(['staff_notifications.starts_at' => now()->toISOString()]);
        $this->work($next);
        $this->assertSame('cancelled', $next->fresh()->status);
        $this->capture();
        $expired = StaffNotificationDelivery::latest('id')->firstOrFail();
        $this->travel(31)->minutes();
        $this->work($expired);
        $this->assertSame('cancelled', $expired->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_revoked_permission_and_logged_out_token_cancel_push_before_sending(): void
    {
        $this->enable();
        Queue::fake();
        config(['staff_notifications.telegram.enabled' => false]);
        $user = $this->staff();
        $subscription = $this->subscription($user);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $user->removeRole('cashier');
        $this->work($delivery);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $user->assignRole('cashier');
        $this->capture();
        $next = StaffNotificationDelivery::latest('id')->firstOrFail();
        $subscription->accessToken->delete();
        $this->assertDatabaseMissing('staff_push_subscriptions', ['id' => $subscription->id]);
        $this->work($next);
        $this->assertSame('cancelled', $next->fresh()->status);
    }

    public function test_push_expired_endpoint_is_removed_and_payload_contains_only_order_reference(): void
    {
        $this->enable();
        Queue::fake();
        config(['staff_notifications.telegram.enabled' => false, 'staff_notifications.push.public_key' => 'public',
            'staff_notifications.push.private_key' => 'private', 'staff_notifications.push.subject' => 'mailto:admin@example.test']);
        $subscription = $this->subscription($this->staff());
        $order = $this->capture();
        $push = \Mockery::mock(WebPush::class);
        $push->shouldReceive('sendOneNotification')->once()->withArgs(function ($target, $payload, $options) use ($order): bool {
            $data = json_decode($payload, true);

            return $data['order_id'] === $order->uuid && $data['url'] === '/admin/orders/'.$order->uuid
                && ! str_contains($payload, $order->customer_phone) && $options['TTL'] > 0;
        })->andReturn(new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/x'), new Response(410), false));
        $sender = \Mockery::mock(StaffNotificationSender::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $sender->shouldReceive('webPush')->once()->andReturn($push);
        $this->app->instance(StaffNotificationSender::class, $sender);
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->work($delivery);
        $this->assertSame('subscription_expired', $delivery->fresh()->error_code);
        $this->assertDatabaseMissing('staff_push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_queue_dispatch_failure_leaves_recoverable_record_without_sync_sending(): void
    {
        $this->enable();
        config(['staff_notifications.connection' => 'sync']);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->assertSame('queue_unavailable', $delivery->error_code);
        $this->assertSame('pending', $delivery->status);
        config(['staff_notifications.connection' => 'database']);
        Queue::fake();
        $this->artisan('staff-notifications:recover')->assertSuccessful();
        Queue::assertPushed(SendStaffOrderNotification::class, 1);
        Http::assertNothingSent();
    }

    public function test_capture_exception_does_not_break_real_checkout_or_cart_idempotency(): void
    {
        $this->enable();
        $this->mock(StaffOrderNotifications::class)->shouldReceive('capture')->once()->andThrow(new \RuntimeException('TOP_SECRET'));
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '20.00']);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();
        $payload = ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Customer', 'phone' => '+966501234567']];
        $id = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.pricing.total', '20.00')->json('data.id');
        $this->postJson('/api/v1/checkout', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
        Http::assertNothingSent();
    }

    public function test_event_rollback_creates_no_staff_notification(): void
    {
        $this->enable();
        Queue::fake();
        DB::beginTransaction();
        $this->capture();
        $this->assertDatabaseCount('staff_order_notifications', 0);
        DB::rollBack();
        $this->assertDatabaseCount('staff_order_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_real_database_worker_contains_telegram_failure_and_preserves_order(): void
    {
        $this->enable();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        $order = $this->capture();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('staff_notification_deliveries', ['status' => 'failed', 'error_code' => 'telegram_rejected']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_inactive_or_expired_admin_and_disabled_channel_cannot_register_or_receive_push(): void
    {
        $this->enable();
        $user = $this->staff();
        $subscription = $this->subscription($user);
        $subscription->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        Queue::fake();
        $this->capture();
        $this->assertSame(0, StaffNotificationDelivery::where('channel', 'push')->count());
        $this->signIn($user);
        config(['staff_notifications.push.enabled' => false]);
        $this->postJson('/api/v1/admin/staff-notifications/subscriptions', $subscription->subscription)->assertConflict();
        // Removing a subscription remains possible with channel delivery switched off.
        $this->deleteJson('/api/v1/admin/staff-notifications/subscriptions', ['endpoint' => $subscription->subscription['endpoint']])->assertOk();
        $this->assertDatabaseMissing('staff_push_subscriptions', ['id' => $subscription->id]);
        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/staff-notifications')->assertUnauthorized();
    }

    public function test_real_web_push_encrypts_payload_with_aes128gcm_without_external_network(): void
    {
        $this->enable();
        Queue::fake();
        $keys = VAPID::createVapidKeys();
        $browserKeys = VAPID::createVapidKeys();
        config(['staff_notifications.telegram.enabled' => false, 'staff_notifications.push.public_key' => $keys['publicKey'],
            'staff_notifications.push.private_key' => $keys['privateKey'], 'staff_notifications.push.subject' => 'mailto:admin@example.test']);
        $subscription = $this->subscription($this->staff());
        $data = $subscription->subscription;
        $data['keys']['p256dh'] = $browserKeys['publicKey'];
        $subscription->update(['subscription' => $data]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $push = new WebPush(['VAPID' => ['subject' => 'mailto:admin@example.test', 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], [], new Client(['handler' => $stack]));
        $sender = \Mockery::mock(StaffNotificationSender::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $sender->shouldReceive('webPush')->once()->andReturn($push);
        $this->app->instance(StaffNotificationSender::class, $sender);
        $order = $this->capture();
        $this->work(StaffNotificationDelivery::firstOrFail());
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString($order->uuid, (string) $request->getBody());
        $this->assertDatabaseHas('staff_notification_deliveries', ['status' => 'sent']);
    }

    public function test_checkout_enqueues_one_telegram_job_on_redis_only_after_commit_even_when_repeated(): void
    {
        $this->enable();
        config(['staff_notifications.connection' => 'redis', 'staff_notifications.push.enabled' => false]);
        Queue::fake();
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '20.00']);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 2])->assertCreated();
        $payload = ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Test Customer', 'phone' => '+966501234567']];
        DB::beginTransaction();
        $id = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.pricing.total', '40.00')->json('data.id');
        Queue::assertNothingPushed();
        DB::commit();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->assertSame('telegram', $delivery->channel);
        Queue::assertPushedOn('notifications', SendStaffOrderNotification::class, fn ($job) => $job->connection === 'redis' && $job->deliveryId === $delivery->id);
        $this->postJson('/api/v1/checkout', $payload)->assertOk()->assertJsonPath('data.id', $id);
        OrderPlaced::dispatch(Order::where('uuid', $id)->firstOrFail());
        Queue::assertPushed(SendStaffOrderNotification::class, 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('staff_notification_deliveries', 1);
        Http::assertNothingSent();
    }

    public function test_each_distinct_new_order_has_its_own_single_telegram_job(): void
    {
        $this->enable();
        config(['staff_notifications.connection' => 'redis', 'staff_notifications.push.enabled' => false]);
        Queue::fake();
        $first = $this->capture();
        $second = $this->capture();
        $this->capture($first);
        $this->capture($second);
        Queue::assertPushed(SendStaffOrderNotification::class, 2);
        $this->assertDatabaseCount('staff_order_notifications', 2);
        $this->assertDatabaseCount('staff_notification_deliveries', 2);
        Http::assertNothingSent();
    }

    public function test_telegram_message_has_snapshot_items_quantities_total_payment_and_type(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $order = Order::factory()->create(['order_number' => 'TEST-DETAILS', 'type' => 'delivery', 'payment_method' => 'network']);
        OrderItem::factory()->for($order)->create(['product_name' => 'وجبة شيشلي', 'quantity' => 2, 'line_total' => '50.00']);
        OrderItem::factory()->for($order)->create(['product_name' => 'عصير', 'quantity' => 3, 'line_total' => '15.00']);
        $order->refresh();
        $this->capture($order);
        $this->work(StaffNotificationDelivery::firstOrFail());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'طلب جديد #TEST-DETAILS')
            && str_contains($request['text'], 'وجبة شيشلي × 2')
            && str_contains($request['text'], 'عصير × 3')
            && str_contains($request['text'], 'الإجمالي: 65.00 SAR')
            && str_contains($request['text'], 'طريقة الدفع: شبكة')
            && str_contains($request['text'], 'نوع الطلب: توصيل')
            && ! str_contains($request['text'], $order->customer_phone)
            && ! str_contains($request['text'], $order->customer_email)
            && ! isset($request['parse_mode']));
    }

    public function test_explicit_temporary_rejections_back_off_and_stop_after_four_attempts(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 503], 503)]);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        foreach ([30, 60, 120] as $delay) {
            $this->work($delivery);
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertSame(now()->addSeconds($delay)->timestamp, $delivery->fresh()->available_at->timestamp);
            $this->travel($delay)->seconds();
        }
        $this->work($delivery);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('attempts_exhausted', $delivery->fresh()->error_code);
        $this->work($delivery);
        Http::assertSentCount(4);
    }

    public function test_failure_log_uses_safe_codes_and_logging_failure_cannot_resend(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'test-secret TOP_SECRET'], 403)]);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        Log::shouldReceive('warning')->once()->with('staff_notifications.delivery_failed', [
            'delivery_id' => $delivery->id, 'channel' => 'telegram', 'status' => 'failed',
            'error_code' => 'telegram_rejected', 'attempts' => 1,
        ])->andThrow(new \RuntimeException('Log unavailable'));
        $this->work($delivery);
        $this->work($delivery);
        $this->assertSame('failed', $delivery->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_retry_after_beyond_notification_expiry_does_not_retry_early(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 3600]], 429)]);
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $this->work($delivery);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('retry_after_expiry', $delivery->fresh()->error_code);
        $this->travel(31)->minutes();
        $this->work($delivery);
        Http::assertSentCount(1);
    }

    public function test_failed_callback_preserves_success_and_other_terminal_delivery_states(): void
    {
        $this->enable();
        Queue::fake();
        Log::spy();
        foreach (['sent', 'failed', 'cancelled', 'uncertain'] as $status) {
            $this->capture();
            $delivery = StaffNotificationDelivery::latest('id')->firstOrFail();
            $delivery->update(['status' => $status, 'attempts' => 2, 'sent_at' => now(), 'error_code' => 'existing_result']);
            $before = $delivery->fresh()->getAttributes();

            (new SendStaffOrderNotification($delivery->id))->failed(new \RuntimeException('Late worker failure'));

            $this->assertSame($before, $delivery->fresh()->getAttributes());
        }
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public function test_failed_callback_cannot_overwrite_success_recorded_immediately_before_its_update(): void
    {
        $this->enable();
        Queue::fake();
        $this->capture();
        $delivery = StaffNotificationDelivery::firstOrFail();
        $delivery->update(['status' => 'processing', 'attempts' => 2]);
        $successRecorded = false;
        Log::spy();
        // Deterministically interleave another worker's success just before the failure UPDATE.
        DB::connection()->beforeExecuting(function (string $query) use ($delivery, &$successRecorded): void {
            if (! $successRecorded && str_starts_with(strtolower(ltrim($query)), 'update')
                && str_contains($query, 'staff_notification_deliveries')) {
                $successRecorded = true;
                DB::table('staff_notification_deliveries')->where('id', $delivery->id)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'error_code' => null]);
            }
        });

        (new SendStaffOrderNotification($delivery->id))->failed(new \RuntimeException('Late worker failure'));

        $this->assertTrue($successRecorded);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->error_code);
        $this->assertSame(now()->timestamp, $delivery->fresh()->sent_at->timestamp);
        $this->assertSame(2, $delivery->fresh()->attempts);
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public function test_failed_callback_marks_only_pending_and_processing_deliveries_uncertain(): void
    {
        $this->enable();
        Queue::fake();
        Log::spy();
        foreach (['pending', 'processing'] as $status) {
            $this->capture();
            $delivery = StaffNotificationDelivery::latest('id')->firstOrFail();
            $delivery->update(['status' => $status, 'attempts' => 2]);

            (new SendStaffOrderNotification($delivery->id))->failed(new \RuntimeException('Worker interrupted'));

            $this->assertSame('uncertain', $delivery->fresh()->status);
            $this->assertSame('worker_interrupted', $delivery->fresh()->error_code);
            $this->assertSame(2, $delivery->fresh()->attempts);
        }
        Log::shouldHaveReceived('warning')->twice();
        Http::assertNothingSent();
    }
}
