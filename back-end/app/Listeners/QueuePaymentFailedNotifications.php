<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\PaymentFailed;
use App\Services\Notifications\NotificationService;

class QueuePaymentFailedNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function handle(PaymentFailed $event): void
    {
        $this->notifications->queuePayment(NotificationType::PaymentFailed, $event->payment);
    }
}
