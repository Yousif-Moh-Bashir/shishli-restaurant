<?php

namespace App\Actions\Drivers;

use App\Actions\Payments\CollectCashPaymentAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\DriverCashCollection;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectDriverCashAction
{
    public function __construct(private DriverOrderService $orders, private CollectCashPaymentAction $collect) {}

    public function handle(User $driver, Order $order, string $amount): DriverCashCollection
    {
        return DB::transaction(function () use ($driver, $order, $amount): DriverCashCollection {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->owned($driver, $locked);
            abort_if($locked->delivery_task_closed_at !== null, 409);
            abort_unless($locked->payment_method === PaymentMethod::Cash
                && in_array($locked->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)
                && ! in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);
            abort_if($locked->payment_status !== PaymentStatus::Pending, 409);
            if (Money::minor($amount) !== Money::minor($locked->total)) {
                throw ValidationException::withMessages(['amount' => 'المبلغ المحصل يجب أن يساوي المبلغ المطلوب للطلب.']);
            }
            $payment = $this->collect->handle($locked, $driver);

            return DriverCashCollection::create(['driver_id' => $driver->id, 'order_id' => $locked->id, 'payment_id' => $payment->id,
                'amount' => $amount, 'currency' => $locked->currency, 'collected_at' => now()]);
        }, 3);
    }
}
