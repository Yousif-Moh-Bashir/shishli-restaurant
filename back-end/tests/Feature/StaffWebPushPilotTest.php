<?php

namespace Tests\Feature;

use App\Console\Commands\TestStaffWebPushPilot;
use App\Jobs\SendStaffWebPushPilot;
use App\Models\User;
use App\Services\Notifications\StaffWebPushPilot;
use App\Services\Notifications\StaffWebPushTransport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWebPushPilotTest extends TestCase
{
    use DatabaseMigrations;

    private string $url = 'https://shishlifresh.com/api/v1/admin/web-push-pilot';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->user = User::factory()->create()->assignRole('cashier');
        config(['cache.stores.redis' => ['driver' => 'array'],
            'staff_web_push_pilot.enabled' => true,
            'staff_web_push_pilot.user_uuid' => $this->user->uuid,
            'staff_web_push_pilot.campaign_uuid' => (string) Str::uuid(),
            'staff_web_push_pilot.expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
            'staff_notifications.enabled' => false,
            'staff_notifications.push.public_key' => 'test-public',
            'staff_notifications.push.private_key' => 'test-private',
            'staff_notifications.push.subject' => 'https://shishlifresh.com']);
        config(['staff_web_push_pilot.vapid.public_key' => 'test-public', 'staff_web_push_pilot.vapid.private_key' => 'test-private',
            'staff_web_push_pilot.vapid.subject' => 'https://shishlifresh.com']);
    }

    private function signIn(?User $user = null, array $abilities = ['*']): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken(($user ?? $this->user)->createToken('api', $abilities)->plainTextToken);
    }

    protected function tearDown(): void
    {
        $this->app?->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    private function subscription(): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/private-pilot-endpoint',
            'keys' => ['p256dh' => 'B'.str_repeat('A', 86), 'auth' => str_repeat('C', 22)]];
    }

    private function register(): string
    {
        $this->signIn();

        return $this->postJson($this->url.'/subscriptions', $this->subscription())->assertSuccessful()->json('data.id');
    }

    public function test_disabled_expired_overlong_or_automatic_notification_modes_deny_access(): void
    {
        $this->signIn();
        config(['staff_web_push_pilot.enabled' => false]);
        $this->getJson($this->url)->assertNotFound();
        config(['staff_web_push_pilot.enabled' => true, 'staff_notifications.enabled' => true]);
        $this->getJson($this->url)->assertNotFound();
        config(['staff_notifications.enabled' => false, 'staff_web_push_pilot.expires_at' => now()->addDays(2)->format('Y-m-d\TH:i:s\Z')]);
        $this->getJson($this->url)->assertNotFound();
        config(['staff_web_push_pilot.expires_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z')]);
        $this->getJson($this->url)->assertNotFound();
    }

    public function test_only_allowlisted_active_staff_with_admin_token_can_register(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
        $this->signIn(User::factory()->create()->assignRole('cashier'));
        $this->getJson($this->url)->assertNotFound();
        foreach (['customer', 'driver'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            config(['staff_web_push_pilot.user_uuid' => $user->uuid]);
            $this->signIn($user);
            $this->getJson($this->url)->assertForbidden();
        }
        config(['staff_web_push_pilot.user_uuid' => $this->user->uuid]);
        $this->signIn(abilities: ['finance']);
        $this->getJson($this->url)->assertNotFound();
        $this->user->forceFill(['is_active' => false])->save();
        $this->signIn();
        $this->assertContains($this->getJson($this->url)->status(), [401, 403]);
    }

    public function test_origin_endpoint_and_local_environment_cannot_bypass_guards(): void
    {
        $this->signIn();
        $this->withHeader('Origin', 'https://evil.test')->getJson($this->url)->assertNotFound();
        $this->withHeader('Origin', 'https://shishlifresh.com')->getJson('https://wrong.test/api/v1/admin/web-push-pilot')->assertNotFound();
        $data = $this->subscription();
        $data['endpoint'] = 'https://127.0.0.1/private';
        $this->postJson($this->url.'/subscriptions', $data)->assertUnprocessable();
        $this->app->detectEnvironment(fn () => 'local');
        $this->getJson($this->url)->assertNotFound();
    }

    public function test_single_device_idempotence_encryption_and_unsubscribe_cannot_switch_devices(): void
    {
        Queue::fake();
        $id = $this->register();
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertJsonPath('data.id', $id);
        $cache = Cache::store('redis');
        $pilot = app(StaffWebPushPilot::class);
        $this->assertStringNotContainsString('private-pilot-endpoint', $cache->get($pilot->key('subscription')));
        $other = $this->subscription();
        $other['endpoint'] .= '-other';
        $this->postJson($this->url.'/subscriptions', $other)->assertConflict();
        $this->deleteJson($this->url.'/subscriptions/'.$id)->assertSuccessful();
        $this->assertNull($pilot->target($id));
        $this->postJson($this->url.'/subscriptions', $other)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_revoked_login_and_changed_vapid_cancel_target(): void
    {
        $id = $this->register();
        $pilot = app(StaffWebPushPilot::class);
        $this->assertNotNull($pilot->target($id));
        config(['staff_web_push_pilot.vapid.public_key' => 'rotated']);
        $this->assertNull($pilot->target($id));
        config(['staff_web_push_pilot.vapid.public_key' => 'test-public']);
        $this->user->tokens()->delete();
        $this->assertNull($pilot->target($id));
    }

    public function test_worker_sends_once_per_campaign_even_with_different_job_instances(): void
    {
        $id = $this->register();
        $pilot = app(StaffWebPushPilot::class);
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->withArgs(fn ($subscription, $payload, $options) => $subscription === $this->subscription() && json_decode($payload, true)['kind'] === 'staff-push-pilot'
            && ! isset(json_decode($payload, true)['order_id']) && $options['TTL'] === 120)->andReturn(['status' => 'sent']);
        foreach ([1, 2] as $attempt) {
            (new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $id))->handle($pilot, $transport);
        }
        $job = new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $id);
        $job->failed(new \RuntimeException('private exception'));
        $this->assertSame(['status' => 'sent'], Cache::store('redis')->get($pilot->key('result')));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transport_exception_is_sanitized_and_not_retried(): void
    {
        $id = $this->register();
        $pilot = app(StaffWebPushPilot::class);
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('private-pilot-endpoint'));
        $job = new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $id);
        $job->handle($pilot, $transport);
        $job->handle($pilot, $transport);
        $this->assertSame(['status' => 'uncertain', 'error_code' => 'transport_uncertain'], Cache::store('redis')->get($pilot->key('result')));
    }

    public function test_disabled_or_stale_worker_never_sends(): void
    {
        $id = $this->register();
        $pilot = app(StaffWebPushPilot::class);
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldNotReceive('send');
        $job = new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $id);
        $job->expiresAt = now()->subSecond()->timestamp;
        $job->handle($pilot, $transport);
        $this->assertSame('cancelled', Cache::store('redis')->get($pilot->key('result'))['status']);
        config(['staff_web_push_pilot.enabled' => false]);
        (new SendStaffWebPushPilot(config('staff_web_push_pilot.campaign_uuid'), $id))->handle($pilot, $transport);
    }

    public function test_cli_defaults_to_readiness_and_rejects_broadcast(): void
    {
        Queue::fake();
        $this->artisan('staff-notifications:pilot-web-push')->expectsOutput('pilot_ready=true')->assertSuccessful();
        $this->artisan('staff-notifications:pilot-web-push', ['--send' => true, '--subscription' => 'all'])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_other_login_cannot_unsubscribe_before_rebinding(): void
    {
        $id = $this->register();
        $this->signIn();
        $this->deleteJson($this->url.'/subscriptions/'.$id)->assertNotFound();
        $this->assertNotNull(app(StaffWebPushPilot::class)->target($id));
        $this->postJson($this->url.'/subscriptions', $this->subscription())->assertSuccessful();
        $this->deleteJson($this->url.'/subscriptions/'.$id)->assertSuccessful();
    }

    public function test_command_dispatches_one_job_to_isolated_redis_queue(): void
    {
        $id = $this->register();
        $pilot = app(StaffWebPushPilot::class);
        Queue::fake();
        $transport = $this->mock(StaffWebPushTransport::class);
        $transport->shouldReceive('ready')->andReturnTrue();
        $transport->shouldReceive('send')->once()->andReturn(['status' => 'sent']);
        $command = \Mockery::mock(TestStaffWebPushPilot::class)->makePartial();
        $command->shouldReceive('option')->with('send')->andReturnTrue();
        $command->shouldReceive('option')->with('subscription')->andReturn($id);
        $command->shouldReceive('line')->andReturnNull();
        $command->shouldReceive('error')->once()->with('Recipient unavailable or pilot already claimed; no resend.');
        $command->shouldReceive('callSilent')->once()->withArgs(fn ($name, $arguments) => $name === 'queue:work'
            && $arguments['connection'] === 'redis' && $arguments['--queue'] === 'staff-web-push-pilot-'.config('staff_web_push_pilot.campaign_uuid')
            && $arguments['--once'] && $arguments['--tries'] === 1)->andReturnUsing(function () use ($pilot, $transport) {
                Queue::pushed(SendStaffWebPushPilot::class)->first()->handle($pilot, $transport);

                return 0;
            });
        $this->assertSame(0, $command->handle($pilot));
        $this->assertSame(1, $command->handle($pilot));
        Queue::assertPushedOn('staff-web-push-pilot-'.config('staff_web_push_pilot.campaign_uuid'), SendStaffWebPushPilot::class);
        Queue::assertPushed(SendStaffWebPushPilot::class, 1);
    }
}
