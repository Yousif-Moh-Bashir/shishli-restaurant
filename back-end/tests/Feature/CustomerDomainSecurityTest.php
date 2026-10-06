<?php

namespace Tests\Feature;

use App\Http\Resources\Api\V1\AdminCustomerResource;
use App\Http\Resources\Api\V1\AdminCustomerSummaryResource;
use App\Http\Resources\Api\V1\CustomerProfileResource;
use App\Http\Resources\Api\V1\GuestCustomerSummaryResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Customers\CustomerLookupService;
use App\Services\Customers\CustomerStatisticsService;
use App\Services\Customers\GuestCustomerQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerDomainSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function manager(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        return $manager;
    }

    #[DataProvider('phoneSearchFormats')]
    public function test_general_customer_search_matches_normalized_phone_formats(string $search): void
    {
        config(['outbound_notifications.phone_country' => 'SA']);
        $manager = $this->manager();
        $customer = User::factory()->customer()->create(['phone' => '+966501234567']);
        User::factory()->customer()->create(['phone' => '+966509876543']);

        $this->actingAs($manager)->getJson('/api/v1/admin/customers?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->uuid);
    }

    public static function phoneSearchFormats(): array
    {
        return ['local' => ['0501234567'], 'country code' => ['966501234567'], 'formatted international' => ['+966 50 123 4567']];
    }

    public function test_registration_returns_422_for_an_existing_normalized_phone_identity(): void
    {
        config(['outbound_notifications.phone_country' => 'SA']);
        $this->seed(RolePermissionSeeder::class);
        User::factory()->customer()->create(['phone' => '0501234567']);

        $this->postJson('/api/v1/register', ['name' => 'Customer', 'email' => 'duplicate@example.com',
            'phone' => '+966501234567', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'duplicate@example.com']);
    }

    public function test_profile_and_admin_detail_omit_authentication_metadata(): void
    {
        $manager = $this->manager();
        $customer = User::factory()->customer()->verifiedPhone()->create();

        $profile = $this->actingAs($customer)->getJson('/api/v1/profile')->assertOk();
        $admin = $this->actingAs($manager)->getJson('/api/v1/admin/customers/'.$customer->uuid)->assertOk();

        foreach ([$profile->json('data'), $admin->json('data')] as $data) {
            $this->assertSame($customer->uuid, $data['id']);
            foreach (['password', 'remember_token', 'tokens', 'roles', 'permissions', 'phone_normalized', 'phone_verified_at', 'email_verified_at'] as $field) {
                $this->assertArrayNotHasKey($field, $data);
            }
        }
    }

    #[DataProvider('restrictedAdminEndpoints')]
    public function test_customer_without_permission_receives_403_for_each_admin_endpoint(string $method, string $path): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = User::factory()->customer()->create();
        $path = str_replace('{customer}', $customer->uuid, $path);

        $this->actingAs($customer)->json($method, '/api/v1/admin/'.$path)->assertForbidden();
    }

    public static function restrictedAdminEndpoints(): array
    {
        return [
            'list' => ['GET', 'customers'], 'detail' => ['GET', 'customers/{customer}'],
            'orders' => ['GET', 'customers/{customer}/orders'], 'addresses' => ['GET', 'customers/{customer}/addresses'],
            'activate' => ['POST', 'customers/{customer}/activate'], 'deactivate' => ['POST', 'customers/{customer}/deactivate'],
            'guest aggregates' => ['GET', 'guest-customers'], 'guest orders' => ['GET', 'guest-customers/orders?phone=%2B249912345678'],
        ];
    }

    public function test_staff_cannot_be_targeted_by_customer_activation_operations(): void
    {
        $manager = $this->manager();
        $staff = User::factory()->customer()->create();
        $staff->assignRole('cashier');

        $this->actingAs($manager)->postJson('/api/v1/admin/customers/'.$staff->uuid.'/deactivate')->assertNotFound();
        $this->postJson('/api/v1/admin/customers/'.$staff->uuid.'/activate')->assertNotFound();

        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_deactivation_invalidates_real_token_and_login_until_activation_preserving_payments(): void
    {
        $manager = $this->manager();
        $customer = User::factory()->customer()->create();
        $order = Order::factory()->for($customer)->completed()->create();
        $payment = Payment::factory()->for($order)->create();
        $token = $customer->createToken('customer')->plainTextToken;

        $this->actingAs($manager)->postJson('/api/v1/admin/customers/'.$customer->uuid.'/deactivate')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => $customer->email, 'password' => 'password'])->assertUnauthorized();
        $this->assertModelExists($payment);
        $this->assertModelExists($order);

        $this->actingAs($manager)->postJson('/api/v1/admin/customers/'.$customer->uuid.'/activate')->assertOk();
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/v1/login', ['email' => $customer->email, 'password' => 'password'])->assertOk();
    }

    public function test_claimed_order_rejects_its_previous_guest_access_token(): void
    {
        $customer = User::factory()->verifiedPhone()->create();
        $order = Order::factory()->guest()->create(['customer_phone' => $customer->phone, 'access_token' => str_repeat('g', 64)]);

        $this->actingAs($customer)->postJson('/api/v1/profile/claim-orders')->assertOk()->assertJsonPath('data.claimed_orders', 1);
        $this->withHeader('X-Order-Token', $order->access_token)->getJson('/api/v1/orders/'.$order->uuid.'/guest')->assertNotFound();
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.id', $order->uuid);
    }

    public function test_all_customer_resources_serialize_preloaded_data_without_queries(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = User::factory()->customer()->create();
        Order::factory()->for($customer)->completed()->create(['total' => '1.25']);
        Order::factory()->guest()->completed()->create(['customer_phone' => '+249912345678']);
        $profile = app(CustomerStatisticsService::class)->loadProfile($customer)->load('orders.branch');
        $summary = app(CustomerLookupService::class)->search([])->firstOrFail();
        $guest = app(GuestCustomerQuery::class)->summaries([])->first();
        $resources = [new CustomerProfileResource($profile), new AdminCustomerResource($profile),
            new AdminCustomerSummaryResource($summary), new GuestCustomerSummaryResource($guest)];

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($resources as $resource) {
                $resource->toResponse(request())->getContent();
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_statistics_use_order_placement_dates_and_completed_totals_regardless_of_paid_state(): void
    {
        $customer = User::factory()->create();
        Order::factory()->for($customer)->completed()->create(['total' => '10.05', 'payment_status' => 'pending', 'placed_at' => '2026-01-01 10:00:00']);
        Order::factory()->for($customer)->create(['total' => '999.99', 'payment_status' => 'paid', 'placed_at' => '2026-01-31 23:59:59']);

        $this->actingAs($customer)->getJson('/api/v1/profile')->assertOk()
            ->assertJsonPath('data.statistics.total_spent', '10.05')
            ->assertJsonPath('data.statistics.first_order_at', '2026-01-01T10:00:00.000000Z')
            ->assertJsonPath('data.statistics.last_order_at', '2026-01-31T23:59:59.000000Z');
    }

    public function test_guest_order_filters_and_pagination_never_include_registered_orders(): void
    {
        $manager = $this->manager();
        $phone = '+249912345678';
        Order::factory()->guest()->completed()->count(3)->create(['customer_phone' => $phone, 'placed_at' => '2026-01-01 12:00:00']);
        Order::factory()->for(User::factory())->completed()->create(['customer_phone' => $phone, 'placed_at' => '2026-01-01 12:00:00']);
        Order::factory()->guest()->completed()->create(['customer_phone' => $phone, 'placed_at' => '2026-01-02 00:00:00']);
        Order::factory()->guest()->create(['customer_phone' => $phone, 'placed_at' => '2026-01-01 12:00:00']);

        $filters = ['phone' => $phone, 'status' => 'completed', 'type' => 'pickup',
            'date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'per_page' => 2, 'page' => 2];
        $this->actingAs($manager)->getJson('/api/v1/admin/guest-customers/orders?'.http_build_query($filters))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    }
}
