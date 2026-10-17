<?php

namespace App\Actions\Drivers;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use App\Services\Money;
use App\Services\Payments\PaymentStatusWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectDriverPaymentAction
{
    public function __construct(private DriverOrderService $orders, private PaymentStatusWriter $writer) {}

    public function handle(User $driver, Order $order, string $amount): Order
    {
        return DB::transaction(function () use ($driver, $order, $amount): Order {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->owned($driver, $locked);
            abort_unless($locked->payment_method->isOffline()
                && $locked->payment_status === PaymentStatus::Pending
                && in_array($locked->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)
                && ! in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);
            if (Money::minor($amount) !== Money::minor($locked->total)) {
                throw ValidationException::withMessages(['amount' => 'سجّل كامل المبلغ المستلم للطلب.']);
            }

            $payments = $locked->payments()->lockForUpdate()->get();
            abort_if($payments->contains(fn (Payment $payment): bool => in_array($payment->status,
                [PaymentStatus::Paid, PaymentStatus::Processing, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)), 409);
            $pending = $payments->filter(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending);
            $payment = $pending->first();
            if ($pending->count() !== 1 || $payment->provider !== null || $payment->method !== $locked->payment_method
                || Money::minor($payment->amount) !== Money::minor($locked->total) || $payment->currency !== $locked->currency) {
                throw ValidationException::withMessages(['payment' => 'تعذر مطابقة دفعة الطلب. حدّث الطلب أو راجع الفرع.']);
            }

            $this->writer->apply($payment, $locked, PaymentStatus::Paid, actorId: $driver->id);

            return $locked->refresh();
        }, 3);
    }
}
