<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthSessionLifetimeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_login_issues_session_only_token_expiring_after_eight_hours(): void
    {
        $this->freezeTime();
        $user = User::factory()->create()->assignRole(Role::findOrCreate('manager', 'web'));
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.session_only', true)
            ->assertJsonPath('data.expires_at', now()->addHours(8)->toISOString());
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame(now()->addHours(8)->toDateTimeString(), $token->expires_at->toDateTimeString());
    }

    public function test_customer_login_token_expires_after_seven_days(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.session_only', false)
            ->assertJsonPath('data.expires_at', now()->addDays(7)->toISOString());
    }

    public function test_legacy_admin_token_expires_even_when_expires_at_is_null_and_activity_does_not_extend_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T08:00:00Z'));
        $user = User::factory()->create()->assignRole(Role::findOrCreate('manager', 'web'));
        $token = $user->createToken('api')->plainTextToken;
        $this->travelTo(CarbonImmutable::parse('2026-11-05T15:59:59Z'));
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T16:00:00Z'));
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_legacy_customer_token_is_valid_before_seven_days_and_rejected_at_the_limit(): void
    {
        $this->freezeTime();
        $token = User::factory()->create()->createToken('api')->plainTextToken;
        $this->travel(6)->days();
        $this->withToken($token)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->travel(1)->days();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_driver_token_lifetime_is_unchanged(): void
    {
        $this->freezeTime();
        $user = User::factory()->create()->assignRole(Role::findOrCreate('driver', 'web'));
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.expires_at', null)->assertJsonPath('data.session_only', false);
        $this->travel(30)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('data.token'))->getJson('/api/user')->assertOk();
    }

    public function test_finance_token_keeps_its_own_expiration(): void
    {
        $this->freezeTime();
        $user = User::factory()->create()->givePermissionTo(Permission::findOrCreate('finance.access', 'web'));
        $token = $user->createToken('finance', ['finance'], now()->addHours(8))->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/finance/me')->assertOk();
        $this->travel(9)->hours();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/finance/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['*'], now()->addDays(7))->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }
}
