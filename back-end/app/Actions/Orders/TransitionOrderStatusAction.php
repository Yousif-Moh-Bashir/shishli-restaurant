<?php

namespace App\Actions\Orders;

use App\Actions\Payments\SyncOrderPaymentStatusAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderOperationAuthorization;
use App\Services\OrderStateMachine;
use App\Services\Payments\OrderPaymentPolicy;
use App\Services\Payments\PaymentStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransitionOrderStatusAction
{
    public function __construct(private OrderStateMachine $machine, private OrderOperationAuthorization $authorization,
        private OrderPaymentPolicy $paymentPolicy, private SyncOrderPaymentStatusAction $syncPayments,
        private PaymentStateMachine $paymentMachine) {}

    public function handle(Order $order, OrderStatus $target, ?User $actor, OrderStatusSource $source, ?string $note = null, ?string $guestToken = null): Order
    {
        return DB::transaction(function () use ($order, $target, $actor, $source, $note, $guestToken): Order {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($locked, $target, $actor, $source, $guestToken);
            abort_if($locked->status !== $order->status
                || ($locked->status === $target && $target !== OrderStatus::Cancelled), 409,
                'تغيّرت حالة الطلب أو نُفذت العملية بالفعل. حدّث الطلب قبل المحاولة مجددًا.');
            $from = $locked->status;
            if ($target === OrderStatus::Confirmed && ! $this->paymentPolicy->canConfirm($locked)) {
                throw new PaymentException('ORDER_PAYMENT_REQUIRED');
            }
            $locked->status = $this->machine->transition($from, $target, $locked->type);
            $locked->{$target->value.'_at'} = now();
            if ($target === OrderStatus::Cancelled) {
                $note = is_string($note) ? trim($note) : null;
                Validator::make(['reason' => $note], ['reason' => ['required', 'string', 'min:3', 'max:500']])->validate();
                $locked->cancellation_reason = $note;
                $locked->cancelled_by = $actor?->id;
            } else {
                Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:1000']])->validate();
            }
            $locked->save();
            if ($target === OrderStatus::Cancelled && $locked->payment_method === PaymentMethod::Cash) {
                foreach ($locked->payments()->where('method', PaymentMethod::Cash)->where('status', PaymentStatus::Pending)->lockForUpdate()->get() as $payment) {
                    $payment->status = $this->paymentMachine->transition($payment->status, PaymentStatus::Cancelled);
                    $payment->cancelled_at = now();
                    $payment->save();
                }
                $locked = $this->syncPayments->handle($locked);
            }
            $locked->statusHistory()->create([
                'from_status' => $from, 'to_status' => $target, 'changed_by' => $actor?->id,
                'source' => $source, 'note' => $note, 'metadata' => null,
            ]);
            OrderStatusChanged::dispatch($locked, $from, $target, $actor?->id, $source);

            return $locked->load(['branch', 'items.options', 'address']);
        }, 3);
    }
}
