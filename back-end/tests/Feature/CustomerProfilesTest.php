<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\User;
use App\Services\Customers\CustomerStatisticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerProfilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_contains_own_statistics_default_address_and_no_secrets(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->customer()->verifiedPhone()->create();
        $address = CustomerAddress::factory()->for($user)->create(['is_default' => true]);
        Order::factory()->for($user)->completed()->create(['total' => '0.10', 'placed_at' => '2026-01-01 12:00:00']);
        Order::factory()->for($user)->completed()->create(['total' => '0.20', 'placed_at' => '2026-01-02 12:00:00']);
        Order::factory()->for($user)->cancelled()->create(['total' => '80.00']);
        Order::factory()->for($user)->create(['total' => '100.00', 'placed_at' => '2026-09-01 12:00:00']);
        Order::factory()->completed()->create(['total' => '500.00']);
        $response = $this->actingAs($user)->getJson('/api/v1/profile')->assertOk()
            ->assertJsonPath('data.id', $user->uuid)->assertJsonPath('data.phone_verified', true)
            ->assertJsonPath('data.default_address.id', $address->uuid)
            ->assertJsonPath('data.statistics.orders_count', 4)
            ->assertJsonPath('data.statistics.completed_orders_count', 2)
            ->assertJsonPath('data.statistics.cancelled_orders_count', 1)
            ->assertJsonPath('data.statistics.total_spent', '0.30')
            ->assertJsonPath('data.statistics.first_order_at', '2026-01-01T12:00:00.000000Z');
        $data = $response->json('data');
        foreach (['password', 'remember_token', 'roles', 'permissions', 'phone_normalized', 'phone_verified_at'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertNotNull($data['statistics']['last_order_at']);
    }

    public function test_profile_update_persists_name_email_and_resets_email_verification(): void
    {
        $user = User::factory()->create(['phone' => '+249912345678']);
        $this->actingAs($user)->patchJson('/api/v1/profile', ['name' => 'يوسف', 'email' => 'new@example.com'])
            ->assertOk()->assertJsonPath('data.name', 'يوسف')->assertJsonPath('data.email', 'new@example.com');
        $this->assertSame('يوسف', $user->fresh()->name);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('+249912345678', $user->fresh()->phone);
    }

    public function test_profile_allows_clearing_email_and_keeping_current_email(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/profile', ['email' => $user->email])->assertOk();
        $this->patchJson('/api/v1/profile', ['email' => null])->assertOk()->assertJsonPath('data.email', null);
        $this->assertNull($user->fresh()->email);
    }

    #[DataProvider('invalidUpdates')]
    public function test_profile_rejects_invalid_or_sensitive_fields(array $payload, string $field): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->patchJson('/api/v1/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function invalidUpdates(): array
    {
        return [
            [['name' => ''], 'name'], [['name' => str_repeat('x', 151)], 'name'], [['email' => 'invalid'], 'email'],
            [['email' => str_repeat('a', 250).'@example.com'], 'email'],
            [['phone' => '+966501234567'], 'phone'], [['phone_verified_at' => '2026-01-01'], 'phone_verified_at'],
            [['phone_normalized' => '+966501234567'], 'phone_normalized'], [['is_active' => false], 'is_active'],
            [['password' => 'new-password'], 'password'], [['roles' => ['super_admin']], 'roles'],
        ];
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/profile', ['email' => $other->email])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_profile_write_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/v1/profile', ['name' => 'x'])->assertUnauthorized();
        $this->postJson('/api/v1/profile/claim-orders')->assertUnauthorized();
    }

    public function test_statistics_exclude_noncompleted_statuses_and_ignore_payment_timing(): void
    {
        $user = User::factory()->create();
        foreach (OrderStatus::cases() as $status) {
            Order::factory()->for($user)->create(['status' => $status, 'total' => '10.11']);
        }
        $statistics = app(CustomerStatisticsService::class)->forUser($user);
        $this->assertSame(count(OrderStatus::cases()), $statistics->ordersCount);
        $this->assertSame(1, $statistics->completedOrdersCount);
        $this->assertSame(1, $statistics->cancelledOrdersCount);
        $this->assertSame('10.11', $statistics->totalSpent);
    }

    public function test_statistics_preserve_large_decimal_total(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->completed()->count(2)->create(['total' => '9999999999.99']);
        $this->assertSame('19999999999.98', app(CustomerStatisticsService::class)->forUser($user)->totalSpent);
    }

    public function test_empty_statistics_have_zero_counts_and_null_dates(): void
    {
        $this->assertSame(['orders_count' => 0, 'completed_orders_count' => 0, 'cancelled_orders_count' => 0,
            'total_spent' => '0.00', 'first_order_at' => null, 'last_order_at' => null],
            app(CustomerStatisticsService::class)->forUser(User::factory()->create())->toArray());
    }

    public function test_my_orders_apply_filters_and_always_enforce_ownership(): void
    {
        $user = User::factory()->create();
        $matching = Order::factory()->for($user)->completed()->create(['placed_at' => '2026-06-01 12:00:00']);
        Order::factory()->for($user)->cancelled()->create();
        Order::factory()->completed()->create(['placed_at' => '2026-06-01 12:00:00']);
        $this->actingAs($user)->getJson('/api/v1/orders?status=completed&type=pickup&date_from=2026-06-01&date_to=2026-06-01&user_uuid=other')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->uuid);
        $other = Order::factory()->create();
        $this->getJson('/api/v1/orders/'.$other->uuid)->assertNotFound();
    }

    public function test_my_orders_support_pagination_and_delivery_filter(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->count(3)->create(['type' => OrderType::Delivery]);
        Order::factory()->for($user)->pickup()->create();
        $this->actingAs($user)->getJson('/api/v1/orders?type=delivery&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_my_orders_date_to_can_be_used_alone(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->create(['placed_at' => '2026-01-01 23:59:59']);
        Order::factory()->for($user)->create(['placed_at' => '2026-01-02 00:00:00']);
        $this->actingAs($user)->getJson('/api/v1/orders?date_to=2026-01-01')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_phone_change_by_trusted_workflow_clears_verification_and_regenerates_normalized_phone(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $user->phone = '+249911111111';
        $user->save();
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame('+249911111111', $user->fresh()->phone_normalized);
    }
}
