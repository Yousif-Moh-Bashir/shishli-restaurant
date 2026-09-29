<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_can_register_with_phone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'phone' => '+249123456789',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertCreated()->assertJsonPath('data.phone', '+249123456789');

        $user = User::where('uuid', $response->json('data.uuid'))->sole();
        $this->assertSame(['customer'], $user->getRoleNames()->all());
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_phone_returns_422_without_creating_another_user(): void
    {
        $user = User::factory()->create(['phone' => '+249123456789']);

        $this->postJson('/api/v1/register', [
            'name' => 'Another customer',
            'email' => 'another@example.com',
            'phone' => '+249123456789',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('+249123456789', $user->fresh()->phone);
    }

    public function test_database_enforces_unique_phone_numbers(): void
    {
        User::factory()->create(['phone' => '+249123456789']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['phone' => '+249123456789']);
    }

    public function test_invalid_phone_returns_422(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'phone' => 'not-a-phone',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('users', 0);
    }
}
