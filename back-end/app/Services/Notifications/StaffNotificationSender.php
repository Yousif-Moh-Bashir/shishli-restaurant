<?php

namespace App\Services\Notifications;

use App\Models\StaffNotificationDelivery;
use App\Models\StaffPushSubscription;
use Minishlink\WebPush\WebPush;

class StaffNotificationSender
{
    /** @return array{status:string,error_code?:string,retry_after?:int} */
    public function send(StaffNotificationDelivery $delivery): array
    {
        $notifications = app(StaffOrderNotifications::class);
        $notification = $delivery->notification->fresh('order.branch');
        if (! $notifications->channelEnabled($delivery->channel) || ! $notifications->current($notification)) {
            return ['status' => 'cancelled'];
        }
        $order = $notification->order;
        $path = '/admin/orders/'.$order->uuid;
        if ($delivery->channel === 'telegram') {
            if ($delivery->destination !== (string) config('staff_notifications.telegram.chat_id')) {
                return ['status' => 'cancelled', 'error_code' => 'destination_changed'];
            }
            $token = config('staff_notifications.telegram.token');
            $site = rtrim((string) config('seo.site_url'), '/');
            if (! $token || ! filter_var($site, FILTER_VALIDATE_URL) || parse_url($site, PHP_URL_SCHEME) !== 'https') {
                return ['status' => 'failed', 'error_code' => 'configuration_required'];
            }

            return app(StaffTelegramTransport::class)->send(
                $delivery->destination,
                app(StaffTelegramOrderMessage::class)->render($order, $site.$path),
            );
        }
        if ($delivery->channel !== 'push') {
            return ['status' => 'failed', 'error_code' => 'unknown_channel'];
        }
        $subscription = StaffPushSubscription::with(['user', 'accessToken'])->where('uuid', $delivery->destination)->first();
        if ($subscription === null || ! $notifications->subscriptionEligible($subscription)) {
            return ['status' => 'cancelled', 'error_code' => 'recipient_revoked'];
        }
        $data = $subscription->subscription;
        if (! app(StaffPushEndpoints::class)->valid($data['endpoint'] ?? '')) {
            return ['status' => 'failed', 'error_code' => 'invalid_endpoint'];
        }
        if (! config('staff_notifications.push.public_key') || ! config('staff_notifications.push.private_key') || ! config('staff_notifications.push.subject')) {
            return ['status' => 'failed', 'error_code' => 'configuration_required'];
        }
        $payload = json_encode([
            'id' => $notification->uuid, 'order_id' => $order->uuid, 'branch_id' => $order->branch->uuid,
            'title' => 'طلب جديد في شيشلي', 'body' => 'طلب #'.$order->order_number.' بانتظار التأكيد',
            'url' => $path, 'expires_at' => $notification->expires_at->toISOString(),
        ], JSON_THROW_ON_ERROR);
        $result = app(StaffWebPushTransport::class)->send($data, $payload, [
            'TTL' => max(1, (int) now()->diffInSeconds($notification->expires_at)),
            'urgency' => 'high', 'topic' => substr(hash('sha256', $notification->uuid), 0, 32),
        ], $this->webPush());
        if (($result['error_code'] ?? null) === 'subscription_expired') {
            $subscription->delete();
        }

        return $result;
    }

    protected function webPush(): WebPush
    {
        return app(StaffWebPushTransport::class)->client();
    }
}
