<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registers_user_and_returns_201_with_only_public_fields(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $payload = [
            'name' => 'New user',
            'email' => 'new@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
            'id' => 9999,
            'role' => 'super_admin',
            'roles' => ['super_admin'],
            'permissions' => ['settings.manage'],
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'email_verified_at' => '2026-01-01 00:00:00',
        ];

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertCreated();
        $this->assertDatabaseCount('users', 1);
        $user = User::where('email', 'new@example.com')->sole();
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertTrue(Str::isUuid($user->uuid));
        $this->assertNotSame($payload['uuid'], $user->uuid);
        $this->assertNotSame(9999, $user->id);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(['customer'], $user->getRoleNames()->all());
        $this->assertFalse($user->can('settings.manage'));
        $response->assertExactJson([
            'success' => true,
            'message' => 'User registered successfully.',
            'data' => [
                'uuid' => $user->uuid,
                'name' => 'New user',
                'email' => 'new@example.com',
                'phone' => null,
                'email_verified_at' => null,
                'created_at' => $user->created_at->toISOString(),
                'updated_at' => $user->updated_at->toISOString(),
            ],
            'errors' => null,
        ]);
    }

    public function test_returns_422_for_duplicate_email_without_changing_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $original = $user->fresh()->getAttributes();

        $this->postJson('/api/v1/register', [
            'name' => 'Another user',
            'email' => 'existing@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $user->fresh()->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInputs')]
    public function test_returns_422_for_invalid_input_without_creating_user(array $overrides, string $field): void
    {
        $payload = array_replace([
            'name' => 'New user',
            'email' => 'new@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ], $overrides);

        $this->postJson('/api/v1/register', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'البيانات المدخلة غير صحيحة')
            ->assertJsonMissingPath('data')
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing name' => [['name' => null], 'name'],
            'non-string name' => [['name' => []], 'name'],
            'long name' => [['name' => str_repeat('n', 256)], 'name'],
            'missing email' => [['email' => null], 'email'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'non-string email' => [['email' => []], 'email'],
            'long email' => [['email' => str_repeat('a', 250).'@example.com'], 'email'],
            'missing password' => [['password' => null], 'password'],
            'non-string password' => [['password' => []], 'password'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'long password' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'different'], 'password'],
        ];
    }

    public function test_returns_429_after_six_registration_attempts(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/register', [])->assertTooManyRequests();

        $this->assertDatabaseCount('users', 0);
    }
}
