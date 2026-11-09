<?php

namespace App\Console\Commands;

use App\Jobs\SendStaffWebPushPilot;
use App\Services\Notifications\StaffWebPushPilot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

#[Signature('staff-notifications:pilot-web-push {--subscription=} {--send}')]
#[Description('Check production pilot readiness; explicitly send once to its single authenticated device')]
class TestStaffWebPushPilot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StaffWebPushPilot $pilot): int
    {
        $this->line('pilot_ready='.($pilot->ready() ? 'true' : 'false'));
        if (! $this->option('send')) {
            return self::SUCCESS;
        }
        if (! $pilot->ready() || ! Str::isUuid($this->option('subscription'))
            || config('queue.connections.redis.driver') !== 'redis') {
            $this->error('One valid pilot subscription and Redis are required.');

            return self::FAILURE;
        }
        try {
            $cache = Cache::store('redis');
            if ($pilot->target($this->option('subscription')) === null || $cache->has($pilot->key('claimed'))) {
                $this->error('Recipient unavailable or pilot already claimed; no resend.');

                return self::FAILURE;
            }
            $queue = 'staff-web-push-pilot-'.config('staff_web_push_pilot.campaign_uuid');
            if (! $cache->add($pilot->key('dispatched'), true, now()->addDays(2))) {
                $this->error('Pilot already dispatched; no resend.');

                return self::FAILURE;
            }
            config(['cache.default' => 'redis', 'queue.failed.driver' => 'null']);
            Queue::connection('redis')->push(new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $this->option('subscription')), '', $queue);
            $this->callSilent('queue:work', ['connection' => 'redis', '--queue' => $queue,
                '--once' => true, '--stop-when-empty' => true, '--tries' => 1, '--timeout' => 20, '--sleep' => 0]);
            $result = $cache->get($pilot->key('result'));
            $this->line('Result: '.($result['status'] ?? 'unknown'));

            return ($result['status'] ?? null) === 'sent' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Pilot incomplete; private error details suppressed. No automatic retry.');

            return self::FAILURE;
        }
    }
}
