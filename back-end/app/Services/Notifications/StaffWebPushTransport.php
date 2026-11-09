<?php

namespace App\Services\Notifications;

use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class StaffWebPushTransport
{
    public function ready(): bool
    {
        return (bool) $this->setting('public_key') && (bool) $this->setting('private_key') && (bool) $this->setting('subject');
    }

    private function setting(string $key): mixed
    {
        return config('staff_notifications.push.'.$key) ?? config('staff_web_push_pilot.vapid.'.$key);
    }

    /** @param array<string,mixed> $subscription @param array<string,mixed> $options
     * @return array{status:string,error_code?:string,retry_after?:int} */
    public function send(array $subscription, string $payload, array $options, ?WebPush $client = null): array
    {
        if (! $this->ready()) {
            return ['status' => 'failed', 'error_code' => 'configuration_required'];
        }
        if (! app(StaffPushEndpoints::class)->valid($subscription['endpoint'] ?? '')) {
            return ['status' => 'failed', 'error_code' => 'invalid_endpoint'];
        }
        try {
            $report = ($client ?? $this->client())->sendOneNotification(
                Subscription::create($subscription + ['contentEncoding' => 'aes128gcm']), $payload, $options,
            );
            if ($report->isSubscriptionExpired()) {
                return ['status' => 'failed', 'error_code' => 'subscription_expired'];
            }
            if ($report->getResponse()?->getStatusCode() === 429) {
                return ['status' => 'retry', 'retry_after' => max(30, (int) $report->getResponse()->getHeaderLine('Retry-After'))];
            }
            if ($report->getResponse() === null) {
                return ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
            }

            return $report->isSuccess() ? ['status' => 'sent'] : ['status' => 'failed', 'error_code' => 'push_rejected'];
        } catch (Throwable) {
            // Provider exceptions may include private subscription URLs or cryptographic material.
            return ['status' => 'uncertain', 'error_code' => 'transport_uncertain'];
        }
    }

    public function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => $this->setting('subject'),
            'publicKey' => $this->setting('public_key'),
            'privateKey' => $this->setting('private_key'),
        ]], [], new Client(['connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false]));
    }
}
