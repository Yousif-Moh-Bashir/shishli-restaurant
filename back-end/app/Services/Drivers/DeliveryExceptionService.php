<?php

namespace App\Services\Drivers;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

class DeliveryExceptionService
{
    public const PERMISSIONS = ['fail' => 'delivery_exceptions.fail', 'start_return' => 'delivery_exceptions.return',
        'confirm_return' => 'delivery_exceptions.return', 'release' => 'delivery_exceptions.release',
        'cancel' => 'delivery_exceptions.cancel', 'reassign' => 'drivers.reassign',
        'resolve_custody' => 'delivery_exceptions.resolve_custody'];

    public function actions(Order $order, User $actor): array
    {
        if ($order->type->value !== 'delivery' || in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
            || $order->delivery_status === DeliveryStatus::Delivered) {
            return [];
        }
        $actions = [];
        $stage = $order->delivery_status;
        if ($order->delivery_task_closed_at === null) {
            if (in_array($stage, [DeliveryStatus::Assigned, DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)) {
                $actions[] = 'fail';
            }
            if ($order->driver_id !== null && in_array($stage, [DeliveryStatus::Failed, DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)) {
                $actions[] = 'start_return';
            }
            if ($stage === DeliveryStatus::Returning) {
                $actions[] = 'confirm_return';
            }
            if ($stage === DeliveryStatus::Failed && $order->status !== OrderStatus::OutForDelivery
                && $order->delivery_picked_up_at === null && $order->delivery_on_the_way_at === null) {
                $actions[] = 'release';
            }
            if ($stage === DeliveryStatus::Failed) {
                $actions[] = 'resolve_custody';
            }
            if (in_array($order->status, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery], true)
                && ! in_array($stage, [DeliveryStatus::Returning, DeliveryStatus::Returned, DeliveryStatus::Released], true)) {
                $actions[] = 'reassign';
            }
        }
        if ($order->delivery_task_closed_at !== null && in_array($stage, [DeliveryStatus::Returned, DeliveryStatus::Released], true)) {
            $actions[] = 'cancel';
        }

        return array_values(array_filter($actions, fn (string $action): bool => $actor->can(self::PERMISSIONS[$action])));
    }

    public function block(Order $order): ?array
    {
        if ($order->delivery_status !== DeliveryStatus::OnTheWay || $order->delivery_task_closed_at !== null
            || in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            return null;
        }
        if ($order->status !== OrderStatus::OutForDelivery) {
            return ['code' => 'DELIVERY_STATE_REVIEW', 'responsible' => 'admin', 'message' => 'حالات الطلب تحتاج مراجعة الإدارة قبل التسليم.'];
        }
        if ($order->payment_status->value === 'paid') {
            return null;
        }

        return match ($order->payment_method->value) {
            'network' => ['code' => 'NETWORK_CONFIRMATION_REQUIRED', 'responsible' => 'cashier',
                'message' => 'دفع الشبكة لم يُؤكد بعد. يجب على الكاشير أو الموظف المخوّل التحقق من نجاح العملية عبر جهاز الشبكة ثم تأكيد التحصيل. لا يستطيع المندوب تأكيده.'],
            'cash' => ['code' => 'CASH_COLLECTION_REQUIRED', 'responsible' => 'driver', 'message' => 'سجّل استلام كامل المبلغ نقدًا من العميل قبل تأكيد التسليم.'],
            default => ['code' => 'ONLINE_PAYMENT_REVIEW', 'responsible' => 'cashier', 'message' => 'الدفع الإلكتروني غير مؤكد. اطلب من الموظف المخوّل مراجعة نتيجة مزود الدفع؛ لا تسجّل تحصيلًا نقديًا بديلًا.'],
        };
    }
}
