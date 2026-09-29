<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_logout_revokes_current_token_and_preserves_other_sessions(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $anotherUserToken = User::factory()->create()->createToken('another-user')->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/logout')->assertOk()->assertJsonPath('success', true);

        $this->assertNull(PersonalAccessToken::findToken($current));
        $this->assertNotNull(PersonalAccessToken::findToken($other));
        $this->assertNotNull(PersonalAccessToken::findToken($anotherUserToken));
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/profile')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.uuid', $user->uuid);
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    public function test_logout_requires_a_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('existing')->plainTextToken;

        $this->actingAs($user)->postJson('/api/v1/logout')->assertUnauthorized();

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }
}
