<?php

namespace App\Jobs;

use App\Services\Notifications\StaffPushDiagnostics;
use App\Services\Notifications\StaffWebPushTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendStaffWebPushTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public int $expiresAt;

    public function __construct(public string $testId, public string $subscriptionId, public string $userUuid)
    {
        $this->expiresAt = now()->addMinutes(2)->timestamp;
    }

    public function handle(StaffPushDiagnostics $diagnostics, StaffWebPushTransport $transport): void
    {
        if (! $diagnostics->enabled()) {
            return;
        }
        $cache = Cache::store('redis');
        $key = 'staff-web-push-diagnostic:result:'.$this->testId;
        if (! $cache->add('staff-web-push-diagnostic:claimed:'.$this->testId, true, 86400)) {
            return;
        }
        if (! $diagnostics->ready() || now()->timestamp > $this->expiresAt) {
            $cache->put($key, ['status' => 'cancelled'], 600);

            return;
        }
        try {
            $target = $diagnostics->target($this->subscriptionId, $this->userUuid);
            if ($target === null) {
                $cache->put($key, ['status' => 'cancelled', 'error_code' => 'recipient_revoked'], 600);

                return;
            }
            $result = $transport->send($target['subscription'], json_encode([
                'kind' => 'staff-push-diagnostic', 'id' => $this->testId,
                'title' => 'اختبار إشعارات شيشلي', 'body' => 'إشعار تجريبي فقط؛ لم يتم إنشاء أي طلب.',
                'expires_at' => now()->addMinutes(2)->toISOString(),
            ], JSON_THROW_ON_ERROR), ['TTL' => 120, 'urgency' => 'high', 'topic' => substr(hash('sha256', $this->testId), 0, 32)]);
            if (($result['error_code'] ?? null) === 'subscription_expired') {
                $diagnostics->remove($this->subscriptionId, $target['user_id']);
            }
            $cache->put($key, $result, 600);
        } catch (Throwable) {
            $cache->put($key, ['status' => 'uncertain', 'error_code' => 'transport_uncertain'], 600);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            Cache::store('redis')->add('staff-web-push-diagnostic:result:'.$this->testId, ['status' => 'uncertain', 'error_code' => 'worker_interrupted'], 600);
        } catch (Throwable) {
        }
    }
}
