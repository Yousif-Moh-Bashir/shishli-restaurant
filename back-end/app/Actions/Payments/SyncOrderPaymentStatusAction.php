<?php
namespace App\Actions\Payments;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
class SyncOrderPaymentStatusAction
{
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payments = $locked->payments()->get();
            $paid = $payments->contains(fn ($payment): bool => $payment->status === PaymentStatus::Paid
                && $payment->currency === $locked->currency && Money::minor($payment->amount) === Money::minor($locked->total));
            if ($paid) {
                $locked->payment_status = PaymentStatus::Paid;
            } elseif ($payments->isNotEmpty() && $locked->payment_status !== PaymentStatus::Paid
                && ! in_array($locked->payment_status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                $locked->payment_status = $payments->every(fn ($payment): bool => $payment->status === PaymentStatus::Cancelled)
                    ? PaymentStatus::Cancelled : PaymentStatus::Pending;
            }
            $locked->save();
            return $locked;
        }, 3);
    }
}
