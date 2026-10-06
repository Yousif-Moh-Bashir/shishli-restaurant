<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Money;
use App\Services\OrderOperationAuthorization;
use App\Services\OrderStateMachine;
use App\Services\Payments\OrderPaymentPolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminOrderActions
{
    public function __construct(private OrderStateMachine $states, private OrderOperationAuthorization $authorization, private OrderPaymentPolicy $payments) {}

    public function forOrder(Order $order, User $actor): array
    {
        $actions = [];
        foreach ($this->states->allowedTransitions($order->status, $order->type) as $target) {
            if ($target === OrderStatus::Confirmed && ! $this->payments->canConfirm($order)) {
                continue;
            }
            try {
                $this->authorization->authorize($order, $target, $actor, $this->authorization->staffSource($actor));
            } catch (HttpException $e) {
                if ($e->getStatusCode() === 403) {
                    continue;
                } throw $e;
            }
            $actions[] = match ($target) {
                OrderStatus::Confirmed => 'confirm',OrderStatus::Preparing => 'start_preparing',OrderStatus::Ready => 'mark_ready',OrderStatus::OutForDelivery => 'dispatch',OrderStatus::Completed => 'complete',OrderStatus::Cancelled => 'cancel',default => $target->value
            };
        }
        if ($actor->can('payments.collect_cash') && $order->payment_method->isOffline() && $order->payment_status !== PaymentStatus::Paid && $order->status !== OrderStatus::Cancelled && $order->relationLoaded('payments') && ! $order->payments->contains(fn (Payment $payment): bool => $payment->method === $order->payment_method && $payment->status === PaymentStatus::Paid)) {
            $pending = $order->payments->first(fn (Payment $payment): bool => $payment->method === $order->payment_method && $payment->status === PaymentStatus::Pending && $payment->provider === null);
            if ($pending !== null && Money::minor($pending->amount) === Money::minor($order->total) && $pending->currency === $order->currency) {
                $actions[] = 'collect_cash';
            }
        }

        return $actions;
    }
}
