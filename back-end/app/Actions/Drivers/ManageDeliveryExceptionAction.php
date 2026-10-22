<?php

namespace App\Actions\Drivers;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use App\Services\Drivers\DeliveryExceptionService;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ManageDeliveryExceptionAction
{
    public function __construct(private DeliveryExceptionService $exceptions, private DriverOrderService $drivers,
        private ReassignDeliveryAction $reassign, private TransitionOrderStatusAction $transition,
        private AdminActivityService $audit) {}

    public function handle(User $actor, Order $order, array $data, bool $intervention = false): Order
    {
        Validator::make($data, ['action' => ['required', 'in:request_intervention,fail,start_return,confirm_return,release,cancel,reassign,resolve_custody'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'operation_key' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'], 'driver_uuid' => ['required_if:action,reassign', 'nullable', 'uuid'],
            'acknowledged' => ['exclude_unless:action,confirm_return,release,cancel,reassign,resolve_custody', 'required', 'accepted'],
            'custody_outcome' => ['exclude_unless:action,resolve_custody', 'required', 'in:lost,damaged,disposed']])->validate();
        $action = $data['action'];
        abort_unless($actor->is_active, 403);
        if ($intervention) {
            abort_unless($action === 'request_intervention', 403);
            $this->drivers->owned($actor, $order);
        } else {
            abort_unless(isset(DeliveryExceptionService::PERMISSIONS[$action]) && $actor->can(DeliveryExceptionService::PERMISSIONS[$action]), 403);
        }

        try {
            return DB::transaction(function () use ($actor, $order, $data, $action, $intervention): Order {
                $target = $action === 'reassign' ? User::where('uuid', $data['driver_uuid'])->firstOrFail() : null;
                User::whereIn('id', array_filter([$order->driver_id, $target?->id]))->orderBy('id')->lockForUpdate()->get();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($intervention) {
                    $this->drivers->owned($actor, $locked);
                }
                $fingerprint = hash('sha256', json_encode([$locked->id, $actor->id, $action, trim($data['reason']),
                    (int) $data['expected_revision'], $data['driver_uuid'] ?? null, $data['acknowledged'] ?? null, $data['custody_outcome'] ?? null], JSON_THROW_ON_ERROR));
                $previous = DB::table('delivery_operations')->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                if ($previous !== null) {
                    abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, 'مفتاح العملية مستخدم لطلب مختلف.');

                    return $locked;
                }
                abort_if($locked->driver_id !== $order->driver_id, 409, 'تغير إسناد الطلب. حدّث الصفحة.');
                abort_if($locked->delivery_revision !== (int) $data['expected_revision'], 409, 'تغيرت حالة المهمة. حدّث الطلب قبل تنفيذ الإجراء.');
                if ($intervention) {
                    abort_unless($locked->type->value === 'delivery' && $locked->delivery_task_closed_at === null
                        && ! in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
                        && in_array($locked->delivery_status?->value, DriverOrderService::ACTIVE_STATUSES, true)
                        && $locked->delivery_exception_requested_at === null, 409);
                } else {
                    abort_unless(in_array($action, $this->exceptions->actions($locked, $actor), true), 409, 'الإجراء غير متاح في حالة الطلب الحالية.');
                }
                DB::table('delivery_operations')->insert(['order_id' => $locked->id, 'actor_id' => $actor->id,
                    'operation_key' => $data['operation_key'], 'fingerprint' => $fingerprint, 'action' => $action, 'created_at' => now()]);
                $from = $locked->delivery_status;
                $reason = $action === 'resolve_custody'
                    ? match ($data['custody_outcome']) {
                        'lost' => 'حسم عهدة طلب مفقود: ', 'damaged' => 'حسم عهدة طلب تالف: ', 'disposed' => 'حسم عهدة طلب أُتلف بإذن الإدارة: '
                    }.trim($data['reason'])
                    : trim($data['reason']);
                $old = ['status' => $locked->status->value, 'delivery_status' => $from?->value, 'driver_uuid' => $locked->driver?->uuid];
                if ($action === 'reassign') {
                    $locked = $this->reassign->handle($actor, $locked, $target, trim($data['reason']));
                } elseif ($action === 'cancel') {
                    $locked = $this->transition->handle($locked, OrderStatus::Cancelled, $actor, OrderStatusSource::DeliveryException, trim($data['reason']));
                } else {
                    $locked->delivery_status = match ($action) {
                        'fail' => DeliveryStatus::Failed, 'start_return' => DeliveryStatus::Returning,
                        'confirm_return' => DeliveryStatus::Returned, 'release', 'resolve_custody' => DeliveryStatus::Released,
                        default => $from,
                    };
                    $locked->delivery_revision++;
                    $locked->delivery_exception_requested_at ??= now();
                    $locked->delivery_exception_reason = $reason;
                    if ($action === 'fail') {
                        $locked->delivery_failed_at = now();
                    }
                    if (in_array($action, ['confirm_return', 'release', 'resolve_custody'], true)) {
                        $locked->delivery_task_closed_at = now();
                    }
                    $locked->save();
                    $locked->deliveryHistory()->create(['driver_id' => $locked->driver_id, 'changed_by' => $actor->id,
                        'from_status' => $from?->value, 'to_status' => $locked->delivery_status?->value, 'reason' => $reason]);
                }
                $this->audit->record($actor, 'delivery_exceptions.'.$action, 'order', $locked->uuid, $old,
                    ['status' => $locked->status->value, 'delivery_status' => $locked->delivery_status?->value,
                        'driver_uuid' => $locked->driver_id === $target?->id ? $target?->uuid : $old['driver_uuid']], $reason);

                return $locked;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'مفتاح العملية مستخدم بالفعل. حدّث الطلب للتحقق من النتيجة.');
        }
    }
}
