<?php

namespace App\Services\Notifications;

use App\Enums\OrderStatus;
use App\Jobs\SendStaffOrderNotification;
use App\Models\Order;
use App\Models\StaffNotificationDelivery;
use App\Models\StaffOrderNotification;
use App\Models\StaffPushSubscription;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Auth\TokenLifetime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StaffOrderNotifications
{
    public function activation(): ?CarbonImmutable
    {
        $startsAt = config('staff_notifications.starts_at');
        if (config('staff_notifications.enabled') !== true || ! is_string($startsAt)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $startsAt)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($startsAt, 'UTC')->utc()->startOfSecond();
        } catch (Throwable) {
            return null;
        }
    }

    public function eligible(?User $user): bool
    {
        return $user !== null && $user->is_active && app(AdminAccessService::class)->allowed($user) && $user->can('orders.view');
    }

    public function subscriptionEligible(StaffPushSubscription $subscription): bool
    {
        $token = $subscription->accessToken;

        return $this->eligible($subscription->user) && $token !== null
            && $token->tokenable_type === User::class && $token->tokenable_id === $subscription->user_id
            && ($token->expires_at === null || $token->expires_at->isFuture())
            && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes((int) config('sanctum.expiration'))))
            && app(TokenLifetime::class)->valid($token, true);
    }

    public function channelEnabled(string $channel): bool
    {
        return $this->activation() !== null && config('staff_notifications.'.$channel.'.enabled') === true;
    }

    public function current(StaffOrderNotification $notification): bool
    {
        $activation = $this->activation();
        $order = $notification->order;

        return $activation !== null && $notification->activated_at->equalTo($activation)
            && $notification->expires_at->isFuture() && $order !== null
            && $order->status === OrderStatus::Pending && $order->placed_at->gte($activation);
    }

    public function capture(Order $order): void
    {
        $activation = $this->activation();
        if ($activation === null || $order->status !== OrderStatus::Pending
            || $order->placed_at->lt($activation) || $order->placed_at->isFuture()
            || $order->placed_at->copy()->addSeconds((int) config('staff_notifications.max_age_seconds'))->isPast()) {
            return;
        }
        DB::transaction(function () use ($order, $activation): void {
            $notification = StaffOrderNotification::firstOrCreate(['order_id' => $order->id], [
                'branch_id' => $order->branch_id, 'activated_at' => $activation,
                'expires_at' => $order->placed_at->copy()->addSeconds((int) config('staff_notifications.max_age_seconds')),
            ]);
            // Snapshot destinations once; replayed events never notify newly subscribed devices.
            if (! $notification->wasRecentlyCreated) {
                return;
            }
            if ($this->channelEnabled('push')) {
                StaffPushSubscription::with(['user.roles.permissions', 'user.permissions', 'accessToken'])->chunkById(100, function ($subscriptions) use ($notification): void {
                    foreach ($subscriptions as $subscription) {
                        if ($this->subscriptionEligible($subscription)) {
                            $this->createDelivery($notification, 'push', $subscription->uuid);
                        }
                    }
                });
            }
            if ($this->channelEnabled('telegram') && config('staff_notifications.telegram.chat_id') && config('staff_notifications.telegram.token')) {
                $this->createDelivery($notification, 'telegram', (string) config('staff_notifications.telegram.chat_id'));
            }
        }, 3);
    }

    private function createDelivery(StaffOrderNotification $notification, string $channel, string $destination): void
    {
        $delivery = StaffNotificationDelivery::firstOrCreate([
            'notification_id' => $notification->id, 'channel' => $channel, 'destination' => $destination,
        ]);
        DB::afterCommit(function () use ($delivery): void {
            try {
                $this->dispatch($delivery);
            } catch (Throwable) {
                // The durable pending record is recoverable even if the queue is unavailable.
            }
        });
    }

    public function dispatch(StaffNotificationDelivery $delivery): void
    {
        try {
            $connection = config('staff_notifications.connection');
            if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                throw new \RuntimeException('Durable queue required');
            }
            $delivery->update(['queued_at' => now()]);
            SendStaffOrderNotification::dispatch($delivery->id);
        } catch (Throwable) {
            $delivery->update(['queued_at' => null, 'error_code' => 'queue_unavailable']);
            try {
                Log::warning('staff_notifications.queue_unavailable', ['delivery_id' => $delivery->id, 'channel' => $delivery->channel]);
            } catch (Throwable) {
            }
        }
    }

    public function recover(): int
    {
        if ($this->activation() === null) {
            return 0;
        }
        // A worker lost during external I/O may have delivered already. Never replay it.
        StaffNotificationDelivery::where('status', 'processing')->where('updated_at', '<', now()->subSeconds(120))
            ->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
        // No historical order scan: only retry already captured delivery records.
        $count = 0;
        StaffNotificationDelivery::where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subSeconds(120)))
            ->with('notification.order')->chunkById(100, function ($deliveries) use (&$count): void {
                foreach ($deliveries as $delivery) {
                    if (! $this->channelEnabled($delivery->channel) || ! $this->current($delivery->notification)) {
                        $delivery->update(['status' => 'cancelled']);
                    } else {
                        $this->dispatch($delivery);
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** @return array<string, mixed> */
    public function feed(): array
    {
        $activation = $this->activation();
        $pending = [];
        if ($activation !== null) {
            $pending = StaffOrderNotification::with(['order.branch'])->where('activated_at', $activation)
                ->where('expires_at', '>', now())->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Pending))
                ->orderBy('id')->limit(200)->get()->map(fn ($notification): array => [
                    'id' => $notification->uuid, 'order_id' => $notification->order->uuid,
                    'branch_id' => $notification->order->branch->uuid, 'order_number' => $notification->order->order_number,
                    'created_at' => $notification->created_at->toISOString(), 'expires_at' => $notification->expires_at->toISOString(),
                    'url' => '/admin/orders/'.$notification->order->uuid,
                ])->all();
        }

        return [
            'enabled' => $activation !== null, 'sound_enabled' => $this->channelEnabled('sound'),
            'push_enabled' => $this->channelEnabled('push') && (bool) config('staff_notifications.push.public_key'),
            'telegram_enabled' => $this->channelEnabled('telegram'),
            'public_key' => $this->channelEnabled('push') ? config('staff_notifications.push.public_key') : null,
            'repeat_seconds' => max(30, (int) config('staff_notifications.sound.repeat_seconds')),
            'pending' => $pending,
        ];
    }
}
