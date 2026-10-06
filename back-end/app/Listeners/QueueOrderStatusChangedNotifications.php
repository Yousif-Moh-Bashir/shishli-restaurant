<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Notifications\NotificationService;

class QueueOrderStatusChangedNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function handle(OrderStatusChanged $event): void
    {
        $type = match ($event->toStatus) {
            OrderStatus::Pending => null,
            OrderStatus::Confirmed => NotificationType::OrderConfirmed,
            OrderStatus::Preparing => NotificationType::OrderPreparing,
            OrderStatus::Ready => NotificationType::OrderReady,
            OrderStatus::OutForDelivery => NotificationType::OrderOutForDelivery,
            OrderStatus::Completed => NotificationType::OrderCompleted,
            OrderStatus::Cancelled => NotificationType::OrderCancelled,
        };
        if ($type !== null) {
            $this->notifications->queueOrder($type, $event->order);
        }
    }
}
