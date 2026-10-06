<?php

namespace App\Services\Notifications;

use App\Enums\NotificationErrorCode;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\OutboundNotification;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDispatcher
{
    public function dispatch(OutboundNotification $notification): void
    {
        $uuid = $notification->uuid;
        DB::afterCommit(function () use ($uuid): void {
            try {
                $connection = config('outbound_notifications.connection', 'database');
                $driver = config('queue.connections.'.$connection.'.driver');
                if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                    throw new \RuntimeException('A durable notification queue is required.');
                }
                $job = new SendNotificationJob($uuid);
                dispatch($job);
            } catch (Throwable) {
                if (isset($job)) {
                    try {
                        (new UniqueLock(app(Repository::class)))->release($job);
                    } catch (Throwable) {
                    }
                }
                try {
                    OutboundNotification::where('uuid', $uuid)->where('status', NotificationStatus::Pending)->update([
                        'status' => NotificationStatus::Failed, 'failed_at' => now(),
                        'last_error_code' => NotificationErrorCode::QueueDispatchFailed->value, 'last_error_message' => NotificationErrorCode::QueueDispatchFailed->message(),
                    ]);
                    Log::warning('notification.queue_dispatch_failed', ['notification_uuid' => $uuid]);
                } catch (Throwable) {
                    // Failure of the operational log must not escape into the committed business operation.
                }
            }
        });
    }
}
