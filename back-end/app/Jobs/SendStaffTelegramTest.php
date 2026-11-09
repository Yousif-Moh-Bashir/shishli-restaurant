<?php

namespace App\Jobs;

use App\Services\Notifications\StaffTelegramTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendStaffTelegramTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public int $expiresAt;

    // The serialized payload contains only a random diagnostic ID, never credentials.
    public function __construct(public string $testId)
    {
        $this->expiresAt = now()->addMinutes(2)->timestamp;
    }

    public function handle(StaffTelegramTransport $transport): void
    {
        if (app()->isProduction() || ! preg_match('/^[a-f0-9-]{36}$/D', $this->testId)) {
            return;
        }
        $cache = Cache::store('redis');
        if (now()->timestamp > $this->expiresAt) {
            $cache->put('staff-telegram-test:result:'.$this->testId, ['status' => 'cancelled', 'error_code' => 'diagnostic_expired'], 600);

            return;
        }
        if (! $cache->add('staff-telegram-test:claimed:'.$this->testId, true, 3600)) {
            return;
        }
        $result = $transport->send(
            (string) config('staff_notifications.telegram.chat_id'),
            "Shishli Telegram queue test\nDiagnostic: ".$this->testId."\nNo order created. No automatic notifications enabled.",
        );
        // No release/retry, including 429 and ambiguous network failures.
        $cache->put('staff-telegram-test:result:'.$this->testId, $result, 600);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            Cache::store('redis')->put('staff-telegram-test:result:'.$this->testId, [
                'status' => 'uncertain', 'error_code' => 'worker_interrupted',
            ], 600);
        } catch (Throwable) {
        }
    }
}
