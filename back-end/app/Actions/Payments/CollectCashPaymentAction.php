<?php

namespace App\Actions\Payments;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Money;
use App\Services\Payments\PaymentStatusWriter;
use Illuminate\Support\Facades\DB;

class CollectCashPaymentAction
{
    public function __construct(private PaymentStatusWriter $writer) {}

    public function handle(Order $order, User $actor): Payment
    {
        return DB::transaction(function () use ($order, $actor): Payment {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $driverCollection = $actor->is_active && $actor->hasRole('driver') && $actor->can('driver.access')
                && $locked->driver_id === $actor->id && $locked->branch_id === $actor->driver_branch_id
                && $locked->payment_method === PaymentMethod::Cash
                && in_array($locked->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true);
            abort_unless($actor->can('payments.collect_cash') || $driverCollection, 403);
            if (! $locked->payment_method->isOffline()) {
                throw new PaymentException('NOT_A_CASH_ORDER');
            }
            if ($locked->status === OrderStatus::Cancelled) {
                throw new PaymentException('ORDER_CANCELLED');
            }
            $paid = $locked->payments()->where('method', $locked->payment_method)->where('status', PaymentStatus::Paid)->lockForUpdate()->first();
            if ($paid !== null) {
                return $paid;
            }
            if ($locked->payment_status === PaymentStatus::Paid) {
                throw new PaymentException('ORDER_ALREADY_PAID');
            }
            $payment = $locked->payments()->where('method', $locked->payment_method)->whereNull('provider')
                ->where('status', PaymentStatus::Pending)->lockForUpdate()->first();
            if ($payment === null) {
                throw new PaymentException('CASH_PAYMENT_NOT_FOUND');
            }
            if (Money::minor($payment->amount) !== Money::minor($locked->total) || $payment->currency !== $locked->currency) {
                throw new PaymentException('PAYMENT_AMOUNT_MISMATCH');
            }
            $this->writer->apply($payment, $locked, PaymentStatus::Paid, actorId: $actor->id);

            return $payment;
        }, 3);
    }
}
