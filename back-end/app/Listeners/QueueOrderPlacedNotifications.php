<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\OrderPlaced;
use App\Services\Notifications\NotificationService;

class QueueOrderPlacedNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function handle(OrderPlaced $event): void
    {
        $this->notifications->queueOrder(NotificationType::OrderPlaced, $event->order);
    }
}
