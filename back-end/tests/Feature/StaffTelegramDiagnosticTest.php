<?php

namespace Tests\Feature;

use App\Jobs\SendStaffTelegramTest;
use App\Services\Notifications\StaffTelegramTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffTelegramDiagnosticTest extends TestCase
{
    // No database trait: these tests must never run migrations or inspect orders.
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'staff_notifications.enabled' => false,
            'staff_notifications.telegram.enabled' => false,
            'staff_notifications.telegram.token' => 'diagnostic-secret',
            'staff_notifications.telegram.chat_id' => '-5486298549',
            'staff_notifications.connection' => 'redis',
            'cache.stores.redis' => ['driver' => 'array'],
        ]);
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
    }

    public function test_readiness_does_not_send_or_access_the_queue_or_expose_secret(): void
    {
        Queue::fake();
        $this->artisan('staff-notifications:test-telegram')
            ->expectsOutput('Chat ID: -5486298549')
            ->expectsOutput('Secret available: yes')
            ->expectsOutput('Queue driver: redis')
            ->expectsOutput('Readiness only. No queue access, message, order, or migration was performed.')
            ->assertSuccessful();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_diagnostic_job_uses_same_transport_once_with_system_disabled(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $job = new SendStaffTelegramTest('11111111-1111-4111-8111-111111111111');
        $job->handle(app(StaffTelegramTransport::class));
        $job->handle(app(StaffTelegramTransport::class));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-5486298549'
            && str_contains($request['text'], 'No order created.')
            && $request['allow_paid_broadcast'] === false);
        $this->assertSame('sent', Cache::store('redis')->get('staff-telegram-test:result:'.$job->testId)['status']);
        $this->assertFalse(config('staff_notifications.enabled'));
        $this->assertFalse(config('staff_notifications.telegram.enabled'));
        $this->assertStringNotContainsString('diagnostic-secret', serialize($job));
    }

    public function test_network_failure_is_sanitized_and_duplicate_job_is_not_retried(): void
    {
        Http::fake(['api.telegram.org/*' => Http::failedConnection('https://api.telegram.org/botdiagnostic-secret/sendMessage')]);
        $job = new SendStaffTelegramTest('22222222-2222-4222-8222-222222222222');
        $job->handle(app(StaffTelegramTransport::class));
        $job->handle(app(StaffTelegramTransport::class));
        $result = Cache::store('redis')->get('staff-telegram-test:result:'.$job->testId);
        $this->assertSame(['status' => 'uncertain', 'error_code' => 'transport_uncertain'], $result);
        $this->assertStringNotContainsString('diagnostic-secret', json_encode($result));
    }

    public function test_rate_limit_is_recorded_without_sending_a_second_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 60]], 429)]);
        $job = new SendStaffTelegramTest('33333333-3333-4333-8333-333333333333');
        $job->handle(app(StaffTelegramTransport::class));
        $job->handle(app(StaffTelegramTransport::class));
        Http::assertSentCount(1);
        $this->assertSame('retry', Cache::store('redis')->get('staff-telegram-test:result:'.$job->testId)['status']);
    }

    public function test_missing_secret_fails_before_enqueuing(): void
    {
        config(['staff_notifications.telegram.token' => null]);
        Queue::fake();
        $this->artisan('staff-notifications:test-telegram', ['--send' => true])->assertFailed();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_production_refuses_the_diagnostic_even_with_send_option(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Queue::fake();
        $this->artisan('staff-notifications:test-telegram', ['--send' => true])->assertFailed();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_expired_diagnostic_cannot_send_later(): void
    {
        $job = new SendStaffTelegramTest('44444444-4444-4444-8444-444444444444');
        $this->travel(3)->minutes();
        $job->handle(app(StaffTelegramTransport::class));
        Http::assertNothingSent();
        $this->assertSame('diagnostic_expired', Cache::store('redis')->get('staff-telegram-test:result:'.$job->testId)['error_code']);
    }
}
