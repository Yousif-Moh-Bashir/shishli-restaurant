<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminIntegrationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('screenMatrix')]
    public function test_each_screen_is_authorized_independently(string $role, string $screen, bool $allowed): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create()->assignRole($role);
        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/admin/'.$screen);
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function screenMatrix(): array
    {
        $screens = ['dashboard' => ['manager', 'cashier'], 'orders' => ['manager', 'cashier', 'kitchen'], 'kitchen/orders' => ['manager', 'cashier', 'kitchen'], 'products' => ['manager', 'cashier'], 'categories' => ['manager'], 'option-groups' => ['manager'], 'branches' => ['manager'], 'inventory' => ['manager'], 'customers' => ['manager', 'cashier'], 'payments' => ['manager', 'cashier'], 'promotions' => ['manager'], 'notifications' => ['manager'], 'pages' => ['manager'], 'banners' => ['manager'], 'gallery' => ['manager'], 'settings' => ['manager'], 'staff' => ['manager'], 'roles' => ['manager'], 'activity-logs' => [], 'reports/orders' => ['manager', 'cashier'], 'reports/payments' => ['manager']];
        $cases = [];
        foreach (['super_admin', 'manager', 'cashier', 'kitchen', 'customer'] as $role) {
            foreach ($screens as $screen => $roles) {
                $cases[$role.' '.$screen] = [$role, $screen, $role === 'super_admin' || in_array($role, $roles, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('listScreens')]
    public function test_admin_lists_reject_invalid_sort_and_page_size(string $screen): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
        $this->getJson('/api/v1/admin/'.$screen.'?sort=name%20desc%3Bdrop%20table%20users')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/admin/'.$screen.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public static function listScreens(): array
    {
        return [['orders'], ['products'], ['categories'], ['branches'], ['customers'], ['payments'], ['promotions'], ['staff'], ['pages'], ['banners'], ['gallery'], ['activity-logs']];
    }

    public function test_financial_widgets_are_hidden_without_report_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('cashier'), 'sanctum');
        $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        foreach (['summary', 'comparison.current', 'comparison.previous', 'comparison.changes'] as $path) {
            $values = data_get($data, $path);
            foreach (['completed_sales', 'average_order_value', 'collected_revenue'] as $field) {
                $this->assertArrayNotHasKey($field, $values);
            }
        }
    }

    public function test_request_id_exists_on_success_validation_and_unknown_routes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
        foreach (['/api/v1/admin/me', '/api/v1/admin/products?per_page=0', '/api/v1/admin/no-such-endpoint'] as $url) {
            $response = $this->getJson($url);
            $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        }
    }

    public function test_no_generic_bulk_or_bulk_order_endpoints_exist(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
        foreach (['/api/v1/admin/bulk', '/api/v1/admin/orders/bulk/cancel', '/api/v1/admin/products/bulk/delete'] as $url) {
            $this->postJson($url, ['model' => 'users', 'action' => 'delete'])->assertNotFound();
        }
    }

    public function test_sensitive_write_limit_is_per_staff_and_returns_request_id(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $first = User::factory()->create()->assignRole('super_admin');
        $second = User::factory()->create()->assignRole('super_admin');
        $this->actingAs($first, 'sanctum');
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/admin/products/bulk/status', [])->assertUnprocessable();
        }
        $response = $this->postJson('/api/v1/admin/products/bulk/status', [])->assertStatus(429);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $this->actingAs($second, 'sanctum')->postJson('/api/v1/admin/products/bulk/status', [])->assertUnprocessable();
    }
}
