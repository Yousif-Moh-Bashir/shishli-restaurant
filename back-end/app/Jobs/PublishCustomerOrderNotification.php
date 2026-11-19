<?php

namespace App\Jobs;

use App\Models\CustomerOrderNotification;
use App\Services\Notifications\CustomerPushNotifications;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishCustomerOrderNotification implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $notificationId)
    {
        $this->onConnection(config('outbound_notifications.connection', 'database'));
        $this->onQueue(config('outbound_notifications.queue', 'notifications'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        CustomerOrderNotification::whereKey($this->notificationId)->whereNull('published_at')->update(['published_at' => now()]);
        app(CustomerPushNotifications::class)->queueNotification($this->notificationId);
    }
}
