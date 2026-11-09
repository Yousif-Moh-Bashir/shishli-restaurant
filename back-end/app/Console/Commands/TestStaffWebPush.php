<?php

namespace App\Console\Commands;

use App\Jobs\SendStaffWebPushTest;
use App\Services\Notifications\StaffPushDiagnostics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

#[Signature('staff-notifications:test-web-push {--subscription=} {--user=} {--test-id=} {--send}')]
#[Description('Check local Web Push diagnostics, or explicitly send once to one authenticated staff subscription')]
class TestStaffWebPush extends Command
{
    public function handle(StaffPushDiagnostics $diagnostics): int
    {
        $this->line('diagnostic_enabled='.($diagnostics->enabled() ? 'true' : 'false'));
        $this->line('vapid_ready='.($diagnostics->ready() ? 'true' : 'false'));
        if (! $this->option('send')) {
            return self::SUCCESS;
        }
        $subscription = $this->option('subscription');
        $user = $this->option('user');
        $testId = $this->option('test-id') ?: (string) Str::uuid();
        if (! $diagnostics->ready() || ! Str::isUuid($subscription) || ! Str::isUuid($user) || ! Str::isUuid($testId)
            || config('queue.connections.'.config('staff_notifications.connection').'.driver') !== 'redis') {
            $this->error('Local diagnostics, VAPID, Redis and one valid subscription/user UUID are required.');

            return self::FAILURE;
        }
        try {
            if ($diagnostics->target($subscription, $user) === null) {
                $this->error('No eligible authenticated diagnostic subscription.');

                return self::FAILURE;
            }
            $cache = Cache::store('redis');
            if ($cache->has('staff-web-push-diagnostic:claimed:'.$testId)) {
                $this->error('This diagnostic ID was already claimed. No resend.');

                return self::FAILURE;
            }
            $queue = 'staff-web-push-test-'.$testId;
            $this->line('Diagnostic ID: '.$testId);
            config(['cache.default' => 'redis', 'queue.failed.driver' => 'null']);
            Queue::connection(config('staff_notifications.connection'))->push(new SendStaffWebPushTest($testId, $subscription, $user), '', $queue);
            $this->callSilent('queue:work', ['connection' => config('staff_notifications.connection'), '--queue' => $queue,
                '--once' => true, '--stop-when-empty' => true, '--tries' => 1, '--timeout' => 20, '--sleep' => 0]);
            $result = $cache->get('staff-web-push-diagnostic:result:'.$testId);
            $this->line('Result: '.($result['status'] ?? 'unknown'));

            return ($result['status'] ?? null) === 'sent' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Diagnostic incomplete. No automatic retry; secret error details are suppressed.');

            return self::FAILURE;
        }
    }
}
