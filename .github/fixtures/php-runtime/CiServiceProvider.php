<?php

declare(strict_types=1);

namespace ShishliRuntimeCi;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class CiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ScheduledTaskFailed::class, static function (ScheduledTaskFailed $event): void {
            $token = getenv('CI_SCHEDULE_TOKEN');
            if ($event->task->description === 'runtime-ci-probe'
                && $event->exception->getMessage() === 'CI_INTENTIONAL_SCHEDULE_FAILURE'
                && is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
                DB::table('ci_runtime_probes')->insert(['token' => $token, 'kind' => 'scheduler-fail']);
            }
        });

        $this->app->afterResolving(Schedule::class, static function (Schedule $schedule): void {
            $token = getenv('CI_SCHEDULE_TOKEN');
            if (! is_string($token) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
                return;
            }

            $schedule->call(static function () use ($token): void {
                if (getenv('CI_SCHEDULE_FAIL') === '1') {
                    throw new RuntimeException('CI_INTENTIONAL_SCHEDULE_FAILURE');
                }

                DB::table('ci_runtime_probes')->insert(['token' => $token, 'kind' => 'scheduler']);
            })->name('runtime-ci-probe')->everyMinute();
        });
    }
}
