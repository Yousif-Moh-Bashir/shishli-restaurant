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
                'id' => $user->uuid,
                'phone_verified' => false,
                'is_active' => true,
                'statistics' => ['orders_count' => 0, 'completed_orders_count' => 0, 'cancelled_orders_count' => 0, 'total_spent' => '0.00', 'first_order_at' => null, 'last_order_at' => null],
                'default_address' => null,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => '+249123456789',
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
