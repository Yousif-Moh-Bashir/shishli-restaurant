<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\PublishCustomerOrderNotification;
use App\Models\CustomerOrderNotification;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CustomerOrderNotifications
{
    public function stage(Order $order, OrderStatusHistory $history): ?CustomerOrderNotification
    {
        if ($order->user_id === null) {
            return null;
        }
        if ($history->order_id !== $order->id || $history->to_status !== $order->status) {
            throw new InvalidArgumentException('Notification source must match the recorded order transition.');
        }
        if (($history->to_status === OrderStatus::OutForDelivery && $order->type !== OrderType::Delivery)
            || ($history->to_status === OrderStatus::Completed && $order->type === OrderType::Delivery
                && ($order->delivery_status !== DeliveryStatus::Delivered || $order->delivery_delivered_at === null))) {
            return null;
        }
        [$type, $title, $body] = $this->content($history->to_status, $order->type);
        $notification = CustomerOrderNotification::firstOrCreate(['order_status_history_id' => $history->id], [
            'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => $history->to_status,
            'type' => $type, 'title' => $title, 'body' => $body, 'occurred_at' => $history->created_at,
        ]);
        if ($notification->wasRecentlyCreated) {
            app(CustomerPushNotifications::class)->capture($notification);
        }
        $id = $notification->id;
        if ($notification->published_at === null) {
            DB::afterCommit(fn () => $this->queue($id));
        }

        return $notification;
    }

    public function queue(int $id): void
    {
        try {
            $connection = config('outbound_notifications.connection', 'database');
            if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                throw new \RuntimeException('A durable notification queue is required.');
            }
            PublishCustomerOrderNotification::dispatch($id);
        } catch (Throwable) {
            // The committed outbox row remains available to the recovery command.
            try {
                Log::warning('customer_notifications.queue_unavailable', ['notification_id' => $id]);
            } catch (Throwable) {
                // Logging failure must not escape into a committed order response.
            }
        }
    }

    public function recover(): int
    {
        $ids = CustomerOrderNotification::whereNull('published_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $this->queue((int) $id);
        }

        return $ids->count();
    }

    private function content(OrderStatus $status, OrderType $type): array
    {
        return match ($status) {
            OrderStatus::Pending => [NotificationType::OrderPlaced, 'استلمنا طلبك للمراجعة', 'إرسال الطلب لا يعني قبوله. يتأكد الطلب فقط بعد قبول المطعم.'],
            OrderStatus::Confirmed => [NotificationType::OrderConfirmed, 'قبِل المطعم طلبك', 'سجّل المطعم قبول الطلب. تابع تفاصيله لمعرفة حالته الحالية.'],
            OrderStatus::Preparing => [NotificationType::OrderPreparing, 'بدأ تجهيز طلبك', 'سجّل المطعم بدء تجهيز الطلب.'],
            OrderStatus::Ready => [NotificationType::OrderReady, $type === OrderType::Pickup ? 'طلبك جاهز للاستلام' : 'تم تجهيز طلبك',
                $type === OrderType::Pickup ? 'أعلن المطعم جاهزية الطلب للاستلام من الفرع.' : 'أعلن المطعم جاهزية الطلب؛ لا يعني ذلك بدء التوصيل.'],
            OrderStatus::OutForDelivery => [NotificationType::OrderOutForDelivery, 'بدأ توصيل طلبك', 'سجّل المندوب بدء التوصيل إلى العميل.'],
            OrderStatus::Completed => [NotificationType::OrderCompleted, $type === OrderType::Delivery ? 'تم تسجيل تسليم طلبك' : 'تم تسجيل استلام طلبك',
                $type === OrderType::Delivery ? 'سُجّل تسليم الطلب للعميل.' : 'سُجّل استلام الطلب من الفرع.'],
            OrderStatus::Cancelled => [NotificationType::OrderCancelled, 'أُلغي طلبك', 'سُجّل إلغاء الطلب. راجع حالة الدفع وتواصل مع المطعم عند الحاجة.'],
        };
    }
}
