<?php

namespace App\Jobs;

use App\Models\CustomerPushDelivery;
use App\Services\Notifications\CustomerPushNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCustomerPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 25;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection(config('outbound_notifications.connection', 'database'));
        $this->onQueue(config('outbound_notifications.queue', 'notifications'));
        $this->afterCommit();
    }

    public function handle(CustomerPushNotifications $notifications): void
    {
        $delivery = DB::transaction(function () use ($notifications): ?CustomerPushDelivery {
            $delivery = CustomerPushDelivery::with(['notification.user', 'notification.order', 'subscription.accessToken.tokenable'])->lockForUpdate()->find($this->deliveryId);
            if ($delivery === null || $delivery->status !== 'pending'
                || ($delivery->available_at !== null && $delivery->available_at->isFuture())) {
                return null;
            }
            if (! $notifications->current($delivery)) {
                $delivery->update(['status' => 'cancelled']);

                return null;
            }
            if ($delivery->attempts >= $this->tries) {
                $delivery->update(['status' => 'failed', 'error_code' => 'attempts_exhausted']);
                $this->logFailure($delivery);

                return null;
            }
            $delivery->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1, 'error_code' => null]);

            return $delivery;
        }, 3);
        if ($delivery === null) {
            return;
        }
        try {
            $result = $notifications->send($delivery);
        } catch (Throwable) {
            // A lost response may follow remote acceptance; never blindly resend it.
            $result = ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
        if ($result['status'] === 'retry') {
            $delay = max(30, (int) ($result['retry_after'] ?? 30 * (2 ** min(4, $delivery->attempts - 1))));
            if ($delivery->attempts >= $this->tries) {
                $delivery->update(['status' => 'failed', 'error_code' => 'attempts_exhausted']);
            } elseif (now()->addSeconds($delay)->gte($delivery->expires_at)) {
                $delivery->update(['status' => 'failed', 'error_code' => 'retry_after_expiry']);
            } else {
                $delivery->update(['status' => 'pending', 'available_at' => now()->addSeconds($delay), 'error_code' => $result['error_code'] ?? 'rate_limited']);
                $this->release($delay);
            }
            $this->logFailure($delivery);

            return;
        }
        $delivery->update([
            'status' => $result['status'], 'error_code' => $result['error_code'] ?? null,
            'sent_at' => $result['status'] === 'sent' ? now() : null,
        ]);
        if (in_array($delivery->status, ['failed', 'uncertain'], true)) {
            $this->logFailure($delivery);
        }
    }

    private function logFailure(CustomerPushDelivery $delivery): void
    {
        try {
            Log::warning('customer_push.delivery_failed', [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status, 'error_code' => $delivery->error_code,
                'attempts' => $delivery->attempts,
            ]);
        } catch (Throwable) {
            // Operational logging must not cause another send attempt.
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $updated = CustomerPushDelivery::whereKey($this->deliveryId)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
            if ($updated > 0) {
                $delivery = CustomerPushDelivery::find($this->deliveryId);
                if ($delivery === null || $delivery->status !== 'uncertain' || $delivery->error_code !== 'worker_interrupted') {
                    return;
                }
                $this->logFailure($delivery);
            }
        } catch (Throwable) {
        }
    }
}
