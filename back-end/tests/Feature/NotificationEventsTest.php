<?php

namespace Tests\Feature;

use App\Actions\Notifications\SendNotificationAction;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Jobs\SendNotificationJob;
use App\Models\Order;
use App\Models\OutboundNotification;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['outbound_notifications.channels.sms' => ['enabled' => true, 'provider' => 'fake'], 'outbound_notifications.providers.sms.fake' => FakeSmsProvider::class]);
        foreach (NotificationType::cases() as $type) {
            config(['outbound_notifications.types.'.$type->value.'.sms' => true]);
        }
        $this->app->singleton(FakeSmsProvider::class);
    }

    public function test_order_placed_creates_snapshot_and_one_deduplicated_job(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        OrderPlaced::dispatch($order);
        OrderPlaced::dispatch($order);
        $notification = OutboundNotification::firstOrFail();
        $this->assertSame('order_placed', $notification->type->value);
        $this->assertSame($order->customer_phone, $notification->recipient);
        $this->assertStringContainsString($order->order_number, $notification->content);
        $this->assertSame('order:'.$order->uuid.':order_placed:sms', $notification->deduplication_key);
        $this->assertDatabaseCount('outbound_notifications', 1);
        Queue::assertPushedOn('notifications', SendNotificationJob::class);
        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->assertCount(0, app(FakeSmsProvider::class)->messages);
    }

    #[DataProvider('statusEvents')]
    public function test_status_events_use_correct_template_and_deduplicate(string $status, string $orderType, string $type, string $expectedText): void
    {
        $order = Order::factory()->create(['customer_phone' => '+966501234567', 'type' => $orderType, 'status' => $status]);
        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::from($status), null, OrderStatusSource::System);
        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::from($status), null, OrderStatusSource::System);
        $notification = OutboundNotification::firstOrFail();
        $this->assertSame($type, $notification->type->value);
        $this->assertStringContainsString($expectedText, $notification->content);
        $this->assertStringContainsString($order->order_number, $notification->content);
        $this->assertDatabaseCount('outbound_notifications', 1);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public static function statusEvents(): array
    {
        return [['confirmed', 'pickup', 'order_confirmed', 'تم تأكيد'], ['preparing', 'pickup', 'order_preparing', 'تجهيز'],
            ['ready', 'pickup', 'order_ready', 'جاهز للاستلام'], ['ready', 'delivery', 'order_ready', 'تم تجهيز'],
            ['out_for_delivery', 'delivery', 'order_out_for_delivery', 'خرج للتوصيل'], ['completed', 'pickup', 'order_completed', 'اكتمل'],
            ['cancelled', 'pickup', 'order_cancelled', 'تم إلغاء']];
    }

    public function test_different_status_and_channel_create_distinct_notifications(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        config(['outbound_notifications.channels.email.enabled' => true, 'outbound_notifications.types.order_placed.email' => true]);
        OrderPlaced::dispatch($order);
        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::Confirmed, null, OrderStatusSource::System);
        $this->assertDatabaseCount('outbound_notifications', 3);
        $this->assertSame(3, OutboundNotification::query()->distinct()->count('deduplication_key'));
        Queue::assertPushed(SendNotificationJob::class, 3);
    }

    public function test_out_for_delivery_never_notifies_pickup_and_pending_has_no_mapping(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::OutForDelivery, null, OrderStatusSource::System);
        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::Pending, null, OrderStatusSource::System);
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('paymentEvents')]
    public function test_payment_events_are_configurable_and_reference_payment_uuid(string $event, string $type): void
    {
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        $payment = Payment::factory()->for($order)->create();
        config(['outbound_notifications.types.'.$type.'.sms' => false]);
        $this->dispatchPaymentEvent($event, $payment, $order);
        $this->assertDatabaseCount('outbound_notifications', 0);
        Queue::assertNothingPushed();
        config(['outbound_notifications.types.'.$type.'.sms' => true]);
        $this->dispatchPaymentEvent($event, $payment, $order);
        $this->dispatchPaymentEvent($event, $payment, $order);
        $notification = OutboundNotification::firstOrFail();
        $this->assertSame($type, $notification->type->value);
        $this->assertSame('payment', $notification->reference_type);
        $this->assertSame($payment->uuid, $notification->reference_uuid);
        $this->assertSame($order->customer_phone, $notification->recipient);
        $this->assertDatabaseCount('outbound_notifications', 1);
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    private function dispatchPaymentEvent(string $event, Payment $payment, Order $order): void
    {
        if ($event === 'success') {
            PaymentSucceeded::dispatch($payment, $order);
        } else {
            PaymentFailed::dispatch($payment);
        }
    }

    public static function paymentEvents(): array
    {
        return [['success', 'payment_succeeded'], ['failed', 'payment_failed']];
    }

    public function test_missing_email_is_skipped_and_invalid_phone_is_logged_without_job(): void
    {
        $order = Order::factory()->create(['customer_phone' => '0501234567', 'customer_email' => null]);
        config(['outbound_notifications.channels.email.enabled' => true, 'outbound_notifications.types.order_placed.email' => true]);
        OrderPlaced::dispatch($order);
        $notification = OutboundNotification::firstOrFail();
        $this->assertSame('INVALID_RECIPIENT', $notification->last_error_code);
        $this->assertSame('failed', $notification->status->value);
        $this->assertSame(0, $notification->attempts);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('outbound_notifications', 1);
    }

    public function test_snapshots_survive_order_customer_and_template_changes(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['customer_phone' => '+966501234567', 'customer_email' => 'snapshot@example.com']);
        config(['outbound_notifications.channels.whatsapp' => ['enabled' => true, 'provider' => 'fake'], 'outbound_notifications.types.order_placed.whatsapp' => true, 'outbound_notifications.whatsapp_templates.order_placed' => ['name' => 'approved_original', 'language' => 'ar']]);
        OrderPlaced::dispatch($order);
        $original = OutboundNotification::where('channel', 'whatsapp')->firstOrFail()->getAttributes();
        $user->update(['phone' => '+249912345678', 'name' => 'Profile changed']);
        $order->update(['customer_name' => 'Order changed', 'customer_phone' => '+249912345678']);
        config(['outbound_notifications.whatsapp_templates.order_placed' => ['name' => 'approved_changed', 'language' => 'en']]);
        OrderPlaced::dispatch($order);
        $this->assertSame($original, OutboundNotification::where('channel', 'whatsapp')->firstOrFail()->getAttributes());
        app(SendNotificationAction::class)->handle(OutboundNotification::where('channel', 'sms')->firstOrFail()->uuid);
        $this->assertSame('+966501234567', app(FakeSmsProvider::class)->messages[0]->recipient);
    }

    public function test_notification_creation_failure_is_contained_and_other_channels_continue(): void
    {
        config(['outbound_notifications.channels.email.enabled' => true, 'outbound_notifications.types.order_placed.email' => true]);
        $order = Order::factory()->create(['customer_phone' => '+966501234567']);
        Event::listen('eloquent.creating: '.OutboundNotification::class, function (OutboundNotification $notification): void {
            if ($notification->channel->value === 'sms') {
                throw new \RuntimeException('secret');
            }
        });
        OrderPlaced::dispatch($order);
        $this->assertDatabaseCount('outbound_notifications', 1);
        $this->assertSame('email', OutboundNotification::first()->channel->value);
    }
}
