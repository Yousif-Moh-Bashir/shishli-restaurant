<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('identifiers')]
    public function test_customer_can_login_and_use_the_issued_token(string $field, string $value): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => 'customer@example.com', 'phone' => '+249123456789']);
        $user->assignRole('customer');

        $response = $this->postJson('/api/v1/login', [$field => $value, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $user->uuid)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.id');

        $token = $response->json('data.token');
        $storedToken = PersonalAccessToken::findToken($token);
        $this->assertNotNull($storedToken);
        $this->assertSame($user->id, $storedToken->tokenable_id);
        $this->assertNotSame($token, $storedToken->token);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.uuid', $user->uuid);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function identifiers(): array
    {
        return [
            'email' => ['email', 'customer@example.com'],
            'phone' => ['phone', '+249123456789'],
        ];
    }

    public function test_invalid_password_returns_401_without_issuing_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'يجب تسجيل الدخول']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_account_returns_the_same_401_response(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'unknown@example.com', 'password' => 'password'])
            ->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'يجب تسجيل الدخول']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_credentials_returns_422(): void
    {
        $this->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone', 'password']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_supplying_both_identifiers_returns_422(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'customer@example.com',
            'phone' => '+249123456789',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_returns_429_after_five_failed_attempts(): void
    {
        $payload = ['email' => 'unknown@example.com', 'password' => 'incorrect'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/v1/login', $payload)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
