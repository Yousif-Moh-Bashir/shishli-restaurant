<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_user_can_fetch_profile_with_only_public_fields(): void
    {
        $user = User::factory()->create(['phone' => '+249123456789']);
        $token = $user->createToken('profile')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/profile')->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => '+249123456789',
                'email_verified_at' => $user->email_verified_at->toISOString(),
                'created_at' => $user->created_at->toISOString(),
                'updated_at' => $user->updated_at->toISOString(),
            ],
            'errors' => null,
        ]);
    }

    public function test_guest_cannot_access_protected_profile(): void
    {
        $this->get('/api/v1/profile')->assertUnauthorized()->assertExactJson([
            'success' => false,
            'message' => 'يجب تسجيل الدخول',
        ]);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('invalid-token')->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        $this->freezeTime();
        $token = User::factory()->create()->createToken('expired', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
    }
}
