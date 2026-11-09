<?php

namespace App\Jobs;

use App\Models\StaffNotificationDelivery;
use App\Services\Notifications\StaffNotificationSender;
use App\Services\Notifications\StaffOrderNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendStaffOrderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 25;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection(config('staff_notifications.connection'));
        $this->onQueue(config('staff_notifications.queue'));
        $this->afterCommit();
    }

    public function handle(StaffOrderNotifications $notifications, StaffNotificationSender $sender): void
    {
        $delivery = DB::transaction(function () use ($notifications): ?StaffNotificationDelivery {
            $delivery = StaffNotificationDelivery::with('notification.order')->lockForUpdate()->find($this->deliveryId);
            if ($delivery === null || $delivery->status !== 'pending'
                || ($delivery->available_at !== null && $delivery->available_at->isFuture())) {
                return null;
            }
            if (! $notifications->channelEnabled($delivery->channel) || ! $notifications->current($delivery->notification)) {
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
            $result = $sender->send($delivery);
        } catch (Throwable) {
            // A lost response may follow remote acceptance; never blindly resend it.
            $result = ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
        if ($result['status'] === 'retry') {
            $delay = max(30, (int) ($result['retry_after'] ?? 30 * (2 ** min(4, $delivery->attempts - 1))));
            if ($delivery->attempts >= $this->tries) {
                $delivery->update(['status' => 'failed', 'error_code' => 'attempts_exhausted']);
            } elseif (now()->addSeconds($delay)->gte($delivery->notification->expires_at)) {
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

    private function logFailure(StaffNotificationDelivery $delivery): void
    {
        try {
            Log::warning('staff_notifications.delivery_failed', [
                'delivery_id' => $delivery->id, 'channel' => $delivery->channel,
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
            $updated = StaffNotificationDelivery::whereKey($this->deliveryId)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted']);
            if ($updated > 0) {
                $delivery = StaffNotificationDelivery::find($this->deliveryId);
                if ($delivery === null || $delivery->status !== 'uncertain' || $delivery->error_code !== 'worker_interrupted') {
                    return;
                }
                $this->logFailure($delivery);
            }
        } catch (Throwable) {
        }
    }
}
