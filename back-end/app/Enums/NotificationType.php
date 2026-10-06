<?php

namespace App\Enums;

enum NotificationType: string
{
    case OrderPlaced = 'order_placed';
    case OrderConfirmed = 'order_confirmed';
    case OrderPreparing = 'order_preparing';
    case OrderReady = 'order_ready';
    case OrderOutForDelivery = 'order_out_for_delivery';
    case OrderCompleted = 'order_completed';
    case OrderCancelled = 'order_cancelled';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
}
