<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }

    public function test_guest_cannot_access_customer_admin_routes(): void
    {
        $customer = User::factory()->create();
        $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
        $this->getJson('/api/v1/admin/customers/'.$customer->uuid)->assertUnauthorized();
        $this->getJson('/api/v1/admin/guest-customers')->assertUnauthorized();
    }

    #[DataProvider('unprivilegedRoles')]
    public function test_customer_and_kitchen_roles_cannot_view_customer_data(string $role): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user)->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    public static function unprivilegedRoles(): array
    {
        return [['customer'], ['kitchen']];
    }

    public function test_index_lists_only_customers_and_excludes_staff_even_with_customer_role(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();
        foreach (['super_admin', 'manager', 'cashier', 'kitchen'] as $role) {
            $staff = User::factory()->create();
            $staff->assignRole([$role, 'customer']);
        }
        User::factory()->create();
        Order::factory()->for($customer)->completed()->create(['total' => '12.50']);
        $this->actingAs($admin)->getJson('/api/v1/admin/customers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->uuid)->assertJsonPath('data.0.total_spent', '12.50')
            ->assertJsonPath('data.0.orders_count', 1);
        $this->getJson('/api/v1/admin/customers/'.$admin->uuid)->assertNotFound();
    }

    #[DataProvider('searchFields')]
    public function test_search_finds_customers_by_name_phone_and_email(string $search): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create(['name' => 'يوسف أحمد', 'phone' => '+249912345678', 'email' => 'target@example.com']);
        User::factory()->customer()->create(['name' => 'Other']);
        $this->actingAs($admin)->getJson('/api/v1/admin/customers?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->uuid);
    }

    public static function searchFields(): array
    {
        return [['يوسف'], ['912345678'], ['target@example']];
    }

    public function test_search_treats_wildcards_literally_and_binds_sql(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create(['name' => '50%_client']);
        User::factory()->customer()->create(['name' => '50xxclient']);
        $this->actingAs($admin)->getJson('/api/v1/admin/customers?'.http_build_query(['search' => '%_']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->uuid);
        $this->getJson('/api/v1/admin/customers?'.http_build_query(['search' => "' OR 1=1 --"]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_by_activity_dates_orders_email_and_normalized_phone(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->inactive()->create(['phone' => '+249912345678', 'email' => 'target@example.com', 'created_at' => '2026-01-15 12:00:00']);
        Order::factory()->for($customer)->create();
        User::factory()->customer()->create(['created_at' => '2026-01-15 12:00:00']);
        $filters = ['is_active' => 0, 'has_orders' => 1, 'registered_from' => '2026-01-01', 'registered_to' => '2026-01-31', 'email' => $customer->email, 'phone' => '00 249 912345678'];
        $this->actingAs($admin)->getJson('/api/v1/admin/customers?'.http_build_query($filters))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->uuid);
        $this->getJson('/api/v1/admin/customers?has_orders=0')->assertOk()->assertJsonCount(1, 'data');
    }

    #[DataProvider('sorts')]
    public function test_sort_whitelist_is_supported(string $sort): void
    {
        $admin = $this->admin();
        $first = User::factory()->customer()->create(['name' => 'Z', 'created_at' => '2026-06-01']);
        $second = User::factory()->customer()->create(['name' => 'A', 'created_at' => '2026-01-01']);
        Order::factory()->for($first)->completed()->count(2)->create(['total' => '10.00', 'placed_at' => '2026-06-01']);
        Order::factory()->for($second)->completed()->create(['total' => '1.00', 'placed_at' => '2026-01-01']);
        $this->actingAs($admin)->getJson('/api/v1/admin/customers?sort='.$sort.'&direction=desc')
            ->assertOk()->assertJsonPath('data.0.id', $first->uuid);
    }

    public static function sorts(): array
    {
        return [['created_at'], ['name'], ['orders_count'], ['total_spent'], ['last_order_at']];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $key, string $value): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/admin/customers?'.http_build_query([$key => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public static function invalidFilters(): array
    {
        return [['sort', 'password'], ['sort', 'name desc; DROP TABLE users'], ['direction', 'invalid'], ['per_page', '101'],
            ['per_page', '0'], ['page', '0'], ['registered_from', 'invalid'], ['is_active', 'yes'], ['has_orders', 'yes']];
    }

    public function test_customer_show_limits_recent_orders_and_loads_default_address_without_exposing_credentials(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->verifiedPhone()->create();
        $address = CustomerAddress::factory()->for($customer)->create(['is_default' => true]);
        Order::factory()->for($customer)->count(7)->completed()->create(['total' => '10.00']);
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/customers/'.$customer->uuid)->assertOk()
            ->assertJsonCount(5, 'data.recent_orders')->assertJsonPath('data.statistics.orders_count', 7)
            ->assertJsonPath('data.statistics.total_spent', '70.00')->assertJsonPath('data.default_address.id', $address->uuid);
        foreach (['password', 'remember_token', 'tokens', 'permissions', 'roles'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data'));
        }
    }

    public function test_customer_orders_and_addresses_are_scoped_and_read_only(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();
        Order::factory()->for($customer)->completed()->count(3)->create();
        Order::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->create();
        CustomerAddress::factory()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/customers/'.$customer->uuid.'/orders?status=completed&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/customers/'.$customer->uuid.'/addresses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $address->uuid);
        $this->patchJson('/api/v1/admin/customers/'.$customer->uuid.'/addresses/'.$address->uuid, ['city' => 'changed'])->assertNotFound();
    }

    public function test_deactivation_revokes_tokens_preserves_history_and_activation_restores_login(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();
        $order = Order::factory()->for($customer)->create();
        $address = CustomerAddress::factory()->for($customer)->create();
        $customer->createToken('first');
        $customer->createToken('second');
        $this->actingAs($admin)->postJson('/api/v1/admin/customers/'.$customer->uuid.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseHas('orders', ['uuid' => $order->uuid, 'user_id' => $customer->id]);
        $this->assertDatabaseHas('customer_addresses', ['uuid' => $address->uuid, 'user_id' => $customer->id]);
        $this->postJson('/api/v1/admin/customers/'.$customer->uuid.'/activate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_cashier_can_view_but_cannot_activate_or_deactivate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $customer = User::factory()->customer()->create();
        $this->actingAs($cashier)->getJson('/api/v1/admin/customers')->assertOk();
        $this->postJson('/api/v1/admin/customers/'.$customer->uuid.'/activate')->assertForbidden();
        $this->postJson('/api/v1/admin/customers/'.$customer->uuid.'/deactivate')->assertForbidden();
    }

    public function test_inactive_user_cannot_login_or_use_existing_token_for_protected_cart_or_checkout(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'password']);
        $token = $user->createToken('stale')->plainTextToken;
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/cart', [])->assertUnauthorized();
        $this->postJson('/api/v1/checkout', [])->assertUnauthorized();
    }

    public function test_index_query_count_does_not_grow_per_customer(): void
    {
        $admin = $this->admin();
        User::factory()->customer()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/customers')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/admin/customers')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        User::factory()->customer()->count(20)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonCount(20, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small, $large);
    }
}
