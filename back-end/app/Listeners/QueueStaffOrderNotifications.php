<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\Notifications\StaffOrderNotifications;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueStaffOrderNotifications
{
    public function handle(OrderPlaced $event): void
    {
        try {
            app(StaffOrderNotifications::class)->capture($event->order);
        } catch (Throwable) {
            // No notification persistence, queue or logging error may escape checkout.
            try {
                Log::warning('staff_notifications.capture_failed', ['order_uuid' => $event->order->uuid]);
            } catch (Throwable) {
            }
        }
    }
}
