<?php

namespace App\Jobs;

use App\Services\Notifications\StaffWebPushPilot;
use App\Services\Notifications\StaffWebPushTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendStaffWebPushPilot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public int $expiresAt;

    public function __construct(public string $campaign, public string $subscriptionId)
    {
        $this->expiresAt = now()->addMinutes(2)->timestamp;
    }

    /**
     * Execute the job.
     */
    public function handle(StaffWebPushPilot $pilot, StaffWebPushTransport $transport): void
    {
        if (! $pilot->ready() || $this->campaign !== config('staff_web_push_pilot.campaign_uuid')) {
            return;
        }
        $cache = Cache::store('redis');
        if (! $cache->add($pilot->key('claimed'), true, now()->addDays(2))) {
            return;
        }
        try {
            $record = $pilot->target($this->subscriptionId);
            if ($record === null || now()->timestamp > $this->expiresAt) {
                $cache->put($pilot->key('result'), ['status' => 'cancelled'], 86400);

                return;
            }
            $result = $transport->send($record['subscription'], json_encode([
                'kind' => 'staff-push-pilot', 'id' => $this->campaign,
                'title' => 'تجربة إشعارات شِشلي', 'body' => 'إشعار تجريبي واحد، دون إنشاء طلب.',
                'expires_at' => now()->addMinutes(2)->toISOString(),
            ], JSON_THROW_ON_ERROR), ['TTL' => 120, 'urgency' => 'high', 'topic' => substr(hash('sha256', $this->campaign), 0, 32)]);
            $cache->put($pilot->key('result'), $result, 86400);
        } catch (Throwable) {
            $cache->put($pilot->key('result'), ['status' => 'uncertain', 'error_code' => 'transport_uncertain'], 86400);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::store('redis')->add('staff-web-push-pilot:'.$this->campaign.':result', ['status' => 'uncertain', 'error_code' => 'worker_interrupted'], 86400);
    }
}
