<?php

namespace App\Actions\Drivers;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransitionDeliveryAction
{
    public function __construct(private DriverOrderService $orders, private TransitionOrderStatusAction $transition) {}

    public function start(User $driver, Order $order, array $data): Order
    {
        Validator::make($data, ['operation_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'picked_up_and_departing' => ['required', 'accepted']])->validate();

        try {
            return DB::transaction(function () use ($driver, $order, $data): Order {
                $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->orders->owned($driver, $locked);
                $fingerprint = hash('sha256', json_encode([$locked->id, $driver->id, 'start_delivery', (int) $data['expected_revision']], JSON_THROW_ON_ERROR));
                $previous = DB::table('delivery_operations')->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($previous !== null) {
                    abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409);

                    return $locked;
                }
                abort_unless($locked->type->value === 'delivery' && $locked->status === OrderStatus::Ready
                    && $locked->delivery_task_closed_at === null
                    && in_array($locked->delivery_status, [DeliveryStatus::Assigned, DeliveryStatus::PickedUp], true)
                    && $locked->delivery_revision === (int) $data['expected_revision'], 409, 'تغيرت المهمة. حدّث الطلب قبل بدء التوصيل.');
                DB::table('delivery_operations')->insert(['order_id' => $locked->id, 'actor_id' => $driver->id,
                    'operation_key' => $data['operation_key'], 'fingerprint' => $fingerprint, 'action' => 'start_delivery', 'created_at' => now()]);
                if ($locked->delivery_status === DeliveryStatus::Assigned) {
                    $locked = $this->handle($driver, $locked, DeliveryStatus::PickedUp, 'أقر المندوب باستلام الطلب والانطلاق الآن.');
                }

                return $this->handle($driver, $locked, DeliveryStatus::OnTheWay, 'بدأ المندوب التوصيل فعليًا.');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'مفتاح العملية مستخدم بالفعل. حدّث الطلب للتحقق من النتيجة.');
        }
    }

    public function handle(User $driver, Order $order, DeliveryStatus $target, ?string $reason, ?int $expectedRevision = null): Order
    {
        return DB::transaction(function () use ($driver, $order, $target, $reason, $expectedRevision): Order {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->owned($driver, $locked);
            abort_if($expectedRevision !== null && $locked->delivery_revision !== $expectedRevision, 409, 'تغيرت المهمة. حدّث الطلب قبل المتابعة.');
            abort_if($locked->delivery_task_closed_at !== null, 409);
            abort_if(in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);
            $from = $locked->delivery_status;
            abort_unless($from !== null && ($target === $from->next() || ($target === DeliveryStatus::Failed && in_array($from, [DeliveryStatus::Assigned, DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true))), 409);
            if ($target === DeliveryStatus::PickedUp && $locked->status !== OrderStatus::Ready) {
                throw ValidationException::withMessages(['status' => 'انتظر حتى يصبح الطلب جاهزًا للاستلام.']);
            }
            if ($target === DeliveryStatus::Failed && (! is_string($reason) || mb_strlen(trim($reason)) < 3)) {
                throw ValidationException::withMessages(['reason' => 'اذكر سبب تعذر التسليم.']);
            }
            if ($target === DeliveryStatus::OnTheWay) {
                $locked = $this->transition->handle($locked, OrderStatus::OutForDelivery, $driver, OrderStatusSource::Driver, $reason);
            }
            if ($target === DeliveryStatus::Delivered) {
                abort_unless($locked->status === OrderStatus::OutForDelivery, 409);
                if ($locked->payment_status !== PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['payment' => 'أكد تحصيل المبلغ قبل تسليم الطلب.']);
                }
            }
            $locked->delivery_status = $target;
            $locked->delivery_revision++;
            if ($target === DeliveryStatus::Failed) {
                $locked->delivery_exception_requested_at ??= now();
                $locked->delivery_exception_reason = $reason;
            }
            if ($target === DeliveryStatus::Delivered) {
                $locked->delivery_task_closed_at = now();
                $locked->delivery_exception_requested_at = null;
            }
            $locked->{'delivery_'.$target->value.'_at'} = now();
            $locked->save();
            $locked->deliveryHistory()->create(['driver_id' => $driver->id, 'changed_by' => $driver->id,
                'from_status' => $from->value, 'to_status' => $target->value, 'reason' => $reason]);

            if ($target === DeliveryStatus::Delivered) {
                return $this->transition->handle($locked, OrderStatus::Completed, $driver, OrderStatusSource::Driver);
            }

            return $locked;
        }, 3);
    }
}
