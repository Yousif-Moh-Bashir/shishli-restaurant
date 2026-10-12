<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[DataProvider('sessionEndpoints')]
    public function test_guest_cannot_open_admin_session(string $endpoint): void
    {
        $this->getJson('/api/v1/admin/'.$endpoint)->assertUnauthorized();
    }

    public static function sessionEndpoints(): array
    {
        return [['me'], ['bootstrap'], ['navigation']];
    }

    #[DataProvider('sessionEndpoints')]
    public function test_customer_cannot_open_admin_session_even_with_a_direct_permission(string $endpoint): void
    {
        $user = User::factory()->create()->assignRole('customer');
        $user->givePermissionTo('orders.view');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/'.$endpoint)->assertForbidden();
    }

    public function test_profile_contains_public_identity_actual_permissions_and_capabilities(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $json = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.id', $user->uuid)->assertJsonPath('data.roles', ['manager'])->assertJsonPath('data.capabilities.can_manage_products', true)->json('data');
        $this->assertContains('staff.create', $json['permissions']);
        $this->assertNotContains('roles.update_permissions', $json['permissions']);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('tokens', $json);
    }

    public function test_bootstrap_is_lightweight_and_contains_only_public_configuration(): void
    {
        $user = User::factory()->create()->assignRole('super_admin');
        $data = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/bootstrap')->assertOk()->json('data');
        $this->assertSame(['admin', 'roles', 'permissions', 'navigation', 'restaurant', 'features'], array_keys($data));
        $this->assertSame(['name', 'currency', 'timezone'], array_keys($data['restaurant']));
        $this->assertFalse($data['features']['contact_messages_enabled']);
        $this->assertTrue($data['features']['cms_enabled']);
        $this->assertSame(97, count($data['permissions']));
        $this->assertContains('drivers.reassign', $data['permissions']);
        $this->assertContains('drivers.settle_cash', $data['permissions']);
    }

    public function test_navigation_uses_actual_permissions_and_does_not_authorize_direct_endpoints(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('orders.view');
        $this->actingAs($user, 'sanctum');
        $keys = array_column($this->getJson('/api/v1/admin/navigation')->assertOk()->json('data'), 'key');
        $this->assertSame(['orders'], $keys);
        $this->getJson('/api/v1/admin/products')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/sales/trend')->assertForbidden();
    }

    public function test_disabled_staff_is_unauthenticated(): void
    {
        $user = User::factory()->create(['is_active' => false])->assignRole('manager');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/bootstrap')->assertUnauthorized();
    }

    public function test_valid_request_id_is_reused_and_invalid_id_is_replaced(): void
    {
        $id = (string) Str::uuid();
        $this->withHeader('X-Request-ID', $id)->getJson('/api/v1/admin/bootstrap')->assertUnauthorized()->assertHeader('X-Request-ID', $id);
        $response = $this->withHeader('X-Request-ID', 'not-a-uuid')->getJson('/api/v1/admin/bootstrap')->assertUnauthorized();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_logging_context_contains_correlation_without_request_secrets(): void
    {
        $id = (string) Str::uuid();
        Log::spy();
        $this->withHeader('X-Request-ID', $id)->getJson('/api/v1/admin/bootstrap?password=must-not-log')->assertUnauthorized();
        Log::shouldHaveReceived('withContext')->once()->with(\Mockery::on(fn (array $context): bool => $context['request_id'] === $id && array_keys($context) === ['request_id', 'user_uuid', 'route'] && ! str_contains(json_encode($context), 'must-not-log')));
    }
}
