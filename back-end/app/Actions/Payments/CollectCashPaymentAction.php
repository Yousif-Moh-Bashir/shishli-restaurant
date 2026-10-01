<?php

namespace App\Actions\Payments;

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
        abort_unless($actor->can('payments.collect_cash'), 403);

        return DB::transaction(function () use ($order, $actor): Payment {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_method !== PaymentMethod::Cash) {
                throw new PaymentException('NOT_A_CASH_ORDER');
            }
            if ($locked->status === OrderStatus::Cancelled) {
                throw new PaymentException('ORDER_CANCELLED');
            }
            $paid = $locked->payments()->where('method', PaymentMethod::Cash)->where('status', PaymentStatus::Paid)->lockForUpdate()->first();
            if ($paid !== null) {
                return $paid;
            }
            if ($locked->payment_status === PaymentStatus::Paid) {
                throw new PaymentException('ORDER_ALREADY_PAID');
            }
            $payment = $locked->payments()->where('method', PaymentMethod::Cash)->whereNull('provider')
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
