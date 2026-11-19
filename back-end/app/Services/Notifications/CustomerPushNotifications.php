<?php

namespace App\Services\Notifications;

use App\Jobs\SendCustomerPush;
use App\Models\CustomerOrderNotification;
use App\Models\CustomerPushDelivery;
use App\Models\CustomerPushSubscription;
use App\Models\User;
use App\Services\Auth\TokenLifetime;
use Carbon\CarbonImmutable;
use Throwable;

class CustomerPushNotifications
{
    public function activation(): ?CarbonImmutable
    {
        $value = config('customer_push.starts_at');
        if (config('customer_push.enabled') !== true || ! app(CustomerWebPushTransport::class)->ready()
            || ! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $value)) {
            return null;
        }
        try {
            $activation = CarbonImmutable::parse($value, 'UTC')->startOfSecond();

            return $activation->isFuture() ? null : $activation;
        } catch (Throwable) {
            return null;
        }
    }

    public function capture(CustomerOrderNotification $notification): void
    {
        $activation = $this->activation();
        if (! $notification->wasRecentlyCreated || $activation === null || $notification->occurred_at->lt($activation)
            || $notification->created_at->lt($activation) || $notification->occurred_at->isFuture()) {
            return;
        }
        $expires = $notification->occurred_at->addSeconds((int) config('customer_push.max_age_seconds', 600));
        if (! $expires->isFuture()) {
            return;
        }
        CustomerPushSubscription::where('user_id', $notification->user_id)->where('consented_at', '<=', $notification->occurred_at)
            ->chunkById(100, function ($devices) use ($notification, $activation, $expires): void {
                foreach ($devices as $device) {
                    CustomerPushDelivery::firstOrCreate(['notification_id' => $notification->id, 'device_uuid' => $device->uuid], [
                        'subscription_id' => $device->id, 'subscription_revision' => $device->revision,
                        'activated_at' => $activation, 'expires_at' => $expires,
                    ]);
                }
            });
    }

    public function current(CustomerPushDelivery $delivery): bool
    {
        $activation = $this->activation();
        $notification = $delivery->notification;
        $device = $delivery->subscription;
        $token = $device?->accessToken;

        return $activation !== null && $delivery->activated_at->equalTo($activation) && $delivery->expires_at->isFuture()
            && $notification !== null && $notification->published_at !== null && $notification->read_at === null
            && $notification->user?->is_active === true && $notification->order?->user_id === $notification->user_id
            && $notification->order->status === $notification->status && $notification->occurred_at->gte($activation)
            && $device !== null && $device->user_id === $notification->user_id && $device->revision === $delivery->subscription_revision
            && $device->consented_at->lte($notification->occurred_at)
            && $token !== null && $token->tokenable_type === User::class && $token->tokenable_id === $device->user_id
            && ($token->expires_at === null || $token->expires_at->isFuture())
            && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes((int) config('sanctum.expiration'))))
            && app(TokenLifetime::class)->valid($token, true);
    }

    public function queueNotification(int $id): void
    {
        CustomerPushDelivery::where('notification_id', $id)->where('status', 'pending')->each(fn ($delivery) => $this->queue($delivery));
    }

    public function queue(CustomerPushDelivery $delivery): void
    {
        try {
            $connection = config('outbound_notifications.connection', 'database');
            if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                throw new \RuntimeException('Durable notification queue required');
            }
            $delivery->update(['queued_at' => now()]);
            SendCustomerPush::dispatch($delivery->id);
        } catch (Throwable) {
            $delivery->update(['queued_at' => null, 'error_code' => 'queue_unavailable']);
        }
    }

    public function send(CustomerPushDelivery $delivery): array
    {
        $delivery = $delivery->fresh(['notification.user', 'notification.order', 'subscription.accessToken.tokenable']);
        if ($delivery === null || ! $this->current($delivery)) {
            return ['status' => 'cancelled'];
        }
        $notification = $delivery->notification;
        $payload = json_encode(['kind' => 'customer-order-push', 'id' => $notification->uuid,
            'binding' => $delivery->device_uuid, 'title' => 'تحديث من شيشلي',
            'body' => $notification->status->value === 'pending' ? 'استلمنا طلبًا للمراجعة. لا يتأكد إلا بعد قبول المطعم.' : 'يوجد تحديث لحالة طلبك. افتح حسابك للاطلاع عليه.',
            'expires_at' => $delivery->expires_at->toISOString(),
        ], JSON_THROW_ON_ERROR);
        $result = app(CustomerWebPushTransport::class)->send($delivery->subscription->subscription, $payload, [
            'TTL' => max(1, (int) now()->diffInSeconds($delivery->expires_at)), 'urgency' => 'normal',
            'topic' => substr(hash('sha256', 'customer:'.$notification->uuid), 0, 32),
        ]);
        if (($result['error_code'] ?? null) === 'subscription_expired') {
            $delivery->subscription->delete();
        }

        return $result;
    }

    public function recover(): int
    {
        CustomerPushDelivery::where('status', 'processing')->where('updated_at', '<', now()->subSeconds(120))
            ->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
        $count = 0;
        CustomerPushDelivery::where('status', 'pending')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subSeconds(120)))
            ->with(['notification.user', 'notification.order', 'subscription.accessToken.tokenable'])->chunkById(100, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    if (! $this->current($row)) {
                        if ($row->notification?->published_at === null && $row->expires_at->isFuture() && $this->activation() !== null) {
                            continue;
                        }
                        $row->update(['status' => 'cancelled']);
                    } else {
                        $this->queue($row);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
