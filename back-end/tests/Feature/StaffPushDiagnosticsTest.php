<?php

namespace Tests\Feature;

use App\Console\Commands\TestStaffWebPush;
use App\Jobs\SendStaffWebPushTest;
use App\Models\User;
use App\Services\Notifications\StaffPushDiagnostics;
use App\Services\Notifications\StaffWebPushTransport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffPushDiagnosticsTest extends TestCase
{
    use DatabaseMigrations;

    private string $url = 'https://staff.test/api/v1/admin/push-diagnostics';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'cache.stores.redis' => ['driver' => 'array'],
            'staff_notifications.enabled' => false,
            'staff_notifications.push_diagnostics.enabled' => true,
            'staff_notifications.push_diagnostics.origin' => 'https://staff.test',
            'staff_notifications.push.public_key' => 'test-public',
            'staff_notifications.push.private_key' => 'test-private',
            'staff_notifications.push.subject' => 'https://staff.test',
        ]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(string $role = 'cashier'): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('diagnostic')->plainTextToken);

        return $user;
    }

    private function subscription(): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/diagnostic-secret',
            'keys' => ['p256dh' => 'B'.str_repeat('A', 86), 'auth' => str_repeat('B', 22)]];
    }

    private function register(): array
    {
        return $this->postJson($this->url.'/subscriptions', $this->subscription())->assertSuccessful()->json('data');
    }

    public function test_diagnostics_require_local_explicit_configuration_and_global_notifications_off(): void
    {
        $this->staff();
        config(['staff_notifications.push_diagnostics.enabled' => false]);
        $this->getJson($this->url)->assertNotFound();
        config(['staff_notifications.push_diagnostics.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson($this->url)->assertNotFound();
        $this->app->detectEnvironment(fn () => 'local');
        config(['staff_notifications.enabled' => true]);
        $this->getJson($this->url)->assertNotFound();
        config(['staff_notifications.enabled' => false, 'staff_notifications.push_diagnostics.origin' => 'https://example.com']);
        $this->getJson($this->url)->assertNotFound();
    }

    public function test_registration_requires_login_and_existing_staff_permissions(): void
    {
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertUnauthorized();
        foreach (['customer', 'driver'] as $role) {
            $this->staff($role);
            $this->postJson($this->url.'/subscriptions', $this->subscription())->assertForbidden();
        }
        $user = $this->staff();
        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertUnauthorized();
    }

    public function test_origin_endpoint_and_missing_vapid_are_rejected(): void
    {
        $this->staff();
        $this->withHeader('Origin', 'https://evil.test')->postJson($this->url.'/subscriptions', $this->subscription())->assertNotFound();
        $this->withHeader('Origin', 'https://staff.test')->getJson('https://other.test/api/v1/admin/push-diagnostics')->assertNotFound();
        $data = $this->subscription();
        $data['endpoint'] = 'https://127.0.0.1/private';
        $this->postJson($this->url.'/subscriptions', $data)->assertUnprocessable();
        config(['staff_notifications.push.private_key' => null]);
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertConflict();
    }

    public function test_registration_is_encrypted_idempotent_and_separate_from_normal_subscriptions(): void
    {
        Queue::fake();
        $user = $this->staff();
        $first = $this->register();
        $this->assertSame($first['id'], $this->register()['id']);
        $this->assertSame($user->uuid, $first['user_id']);
        $value = Cache::store('redis')->get('staff-web-push-diagnostic:subscription:'.$first['id']);
        $this->assertIsString($value);
        $this->assertStringNotContainsString('diagnostic-secret', $value);
        $this->assertStringNotContainsString(str_repeat('B', 22), $value);
        $this->getJson($this->url)->assertJsonPath('data.subscription.id', $first['id']);
        $this->assertDatabaseCount('staff_push_subscriptions', 0);
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_other_users_cannot_take_over_remove_or_target_an_endpoint(): void
    {
        $owner = $this->staff();
        $record = $this->register();
        $other = $this->staff();
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertConflict();
        $this->deleteJson($this->url.'/subscriptions/'.$record['id'])->assertSuccessful();
        $manager = app(StaffPushDiagnostics::class);
        $this->assertNotNull($manager->target($record['id'], $owner->uuid));
        $this->assertNull($manager->target($record['id'], $other->uuid));
        $owner->tokens()->delete();
        $this->assertNull($manager->target($record['id'], $owner->uuid));
    }

    public function test_job_sends_once_without_order_data(): void
    {
        $user = $this->staff();
        $record = $this->register();
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->withArgs(function ($subscription, $payload, $options) {
            $message = json_decode($payload, true);

            return $subscription === $this->subscription() && $message['kind'] === 'staff-push-diagnostic'
                && ! isset($message['order_id']) && $options['TTL'] === 120;
        })->andReturn(['status' => 'sent']);
        $job = new SendStaffWebPushTest((string) Str::uuid(), $record['id'], $user->uuid);
        $job->handle(app(StaffPushDiagnostics::class), $transport);
        $job->handle(app(StaffPushDiagnostics::class), $transport);
        $this->assertSame('sent', Cache::store('redis')->get('staff-web-push-diagnostic:result:'.$job->testId)['status']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transport_exception_is_sanitized_without_retry(): void
    {
        $user = $this->staff();
        $record = $this->register();
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('private-endpoint-secret'));
        $job = new SendStaffWebPushTest((string) Str::uuid(), $record['id'], $user->uuid);
        $job->handle(app(StaffPushDiagnostics::class), $transport);
        $job->handle(app(StaffPushDiagnostics::class), $transport);
        $this->assertSame(['status' => 'uncertain', 'error_code' => 'transport_uncertain'], Cache::store('redis')->get('staff-web-push-diagnostic:result:'.$job->testId));
        $this->assertSame(1, $job->tries);
    }

    public function test_disabled_and_expired_jobs_never_send(): void
    {
        $user = $this->staff();
        $record = $this->register();
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldNotReceive('send');
        $job = new SendStaffWebPushTest((string) Str::uuid(), $record['id'], $user->uuid);
        $job->expiresAt = now()->subMinute()->timestamp;
        $job->handle(app(StaffPushDiagnostics::class), $transport);
        $this->assertSame('cancelled', Cache::store('redis')->get('staff-web-push-diagnostic:result:'.$job->testId)['status']);
        config(['staff_notifications.push_diagnostics.enabled' => false]);
        (new SendStaffWebPushTest((string) Str::uuid(), $record['id'], $user->uuid))->handle(app(StaffPushDiagnostics::class), $transport);
    }

    public function test_command_checks_only_by_default_and_rejects_broadcast_or_disabled_send(): void
    {
        Queue::fake();
        $this->artisan('staff-notifications:test-web-push')->expectsOutput('diagnostic_enabled=true')->assertSuccessful();
        $this->artisan('staff-notifications:test-web-push', ['--send' => true, '--subscription' => 'all'])->assertFailed();
        config(['staff_notifications.push_diagnostics.enabled' => false]);
        $this->artisan('staff-notifications:test-web-push', ['--send' => true])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_command_queues_one_target_on_an_isolated_redis_queue_and_never_resends_the_same_id(): void
    {
        $user = $this->staff();
        $record = $this->register();
        $id = (string) Str::uuid();
        config(['staff_notifications.connection' => 'redis']);
        Queue::fake();
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->andReturn(['status' => 'sent']);
        $command = \Mockery::mock(TestStaffWebPush::class)->makePartial();
        $command->shouldReceive('callSilent')->once()->withArgs(fn ($name, $arguments) => $name === 'queue:work'
            && $arguments['connection'] === 'redis' && $arguments['--queue'] === 'staff-web-push-test-'.$id
            && $arguments['--once'] === true && $arguments['--tries'] === 1)
            ->andReturnUsing(function () use ($transport) {
                Queue::pushed(SendStaffWebPushTest::class)->first()->handle(app(StaffPushDiagnostics::class), $transport);

                return 0;
            });
        $command->shouldReceive('option')->with('send')->andReturnTrue();
        $command->shouldReceive('option')->with('subscription')->andReturn($record['id']);
        $command->shouldReceive('option')->with('user')->andReturn($user->uuid);
        $command->shouldReceive('option')->with('test-id')->andReturn($id);
        $command->shouldReceive('line')->andReturnNull();
        $command->shouldReceive('error')->once()->with('This diagnostic ID was already claimed. No resend.');
        $this->assertSame(0, $command->handle(app(StaffPushDiagnostics::class)));
        $this->assertSame(1, $command->handle(app(StaffPushDiagnostics::class)));
        Queue::assertPushedOn('staff-web-push-test-'.$id, SendStaffWebPushTest::class);
        Queue::assertPushed(SendStaffWebPushTest::class, 1);
    }
}
