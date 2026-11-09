<?php

namespace App\Console\Commands;

use App\Jobs\SendStaffTelegramTest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

#[Signature('staff-notifications:test-telegram {--send : Send exactly one diagnostic message through an isolated Redis queue}')]
#[Description('Check Telegram readiness, or explicitly send one queued diagnostic without orders or migrations')]
class TestStaffTelegram extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Diagnostic sending is not allowed in production.');

            return self::FAILURE;
        }
        $chatId = (string) config('staff_notifications.telegram.chat_id');
        $hasToken = is_string(config('staff_notifications.telegram.token')) && trim(config('staff_notifications.telegram.token')) !== '';
        $connection = (string) config('staff_notifications.connection');
        $isRedis = config('queue.connections.'.$connection.'.driver') === 'redis';
        $this->line('Chat ID: '.$chatId);
        $this->line('Secret available: '.($hasToken ? 'yes' : 'no'));
        $this->line('Queue driver: '.($isRedis ? 'redis' : 'not redis'));
        if (! $this->option('send')) {
            $this->info('Readiness only. No queue access, message, order, or migration was performed.');

            return self::SUCCESS;
        }
        if (! $hasToken || ! preg_match('/^-\d+$/D', $chatId) || ! $isRedis) {
            $this->error('A secret token, group Chat ID and Redis queue configuration are required.');

            return self::FAILURE;
        }
        $testId = (string) Str::uuid();
        $queueName = 'staff-telegram-test-'.$testId;
        $resultKey = 'staff-telegram-test:result:'.$testId;
        $this->line('Diagnostic ID: '.$testId);
        // These overrides last only in this process. Neither channel flags nor environment files change.
        config(['cache.default' => 'redis', 'queue.failed.driver' => 'null']);
        try {
            Cache::store('redis')->put('staff-telegram-test:ready:'.$testId, true, 60);
            Queue::connection($connection)->push(new SendStaffTelegramTest($testId), '', $queueName);
            $this->callSilent('queue:work', [
                'connection' => $connection, '--queue' => $queueName,
                '--once' => true, '--stop-when-empty' => true, '--tries' => 1,
                '--timeout' => 20, '--sleep' => 0,
            ]);
            $result = Cache::store('redis')->get($resultKey);
            if (($result['status'] ?? null) === 'sent') {
                $this->info('Telegram accepted one diagnostic message through Laravel Queue. Confirm receipt in the group.');

                return self::SUCCESS;
            }
            $this->error('No confirmed delivery. Result: '.($result['error_code'] ?? $result['status'] ?? 'worker_result_missing').'. No automatic resend.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Diagnostic incomplete; delivery may be unknown. No automatic resend. No exception details were logged.');

            return self::FAILURE;
        }
    }
}
