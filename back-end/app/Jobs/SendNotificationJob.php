<?php

namespace App\Jobs;

use App\Actions\Notifications\SendNotificationAction;
use App\Enums\NotificationStatus;
use App\Models\OutboundNotification;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotificationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 120;

    public bool $failOnTimeout = true;

    public function __construct(public string $notificationUuid)
    {
        $this->tries = max(1, (int) config('outbound_notifications.max_attempts', 3));
        $this->timeout = (int) config('outbound_notifications.timeout', 30);
        $this->onConnection(config('outbound_notifications.connection', 'database'));
        $this->onQueue(config('outbound_notifications.queue', 'notifications'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->notificationUuid;
    }

    public function backoff(): array
    {
        return config('outbound_notifications.backoff', [30, 120, 600]);
    }

    public function handle(SendNotificationAction $send): void
    {
        if ($send->handle($this->notificationUuid)) {
            $attempts = OutboundNotification::where('uuid', $this->notificationUuid)->value('attempts');
            $delays = $this->backoff();
            $this->release($delays[min(max(0, $attempts - 1), count($delays) - 1)] ?? 30);
        } elseif (OutboundNotification::where('uuid', $this->notificationUuid)->where('status', NotificationStatus::Processing)->exists()) {
            $this->release(max(5, (int) config('outbound_notifications.processing_lease_seconds', 60)));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SendNotificationAction::class)->queueFailed($this->notificationUuid);
    }
}
