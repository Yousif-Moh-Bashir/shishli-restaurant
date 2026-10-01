<?php

namespace Tests\Feature;

use App\Actions\Auth\RegisterUserAction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  list<string>  $allowed
     */
    #[DataProvider('rolePermissions')]
    public function test_role_grants_only_its_expected_permissions(string $role, array $allowed): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        foreach (Permission::pluck('name') as $permission) {
            $this->assertSame(in_array($permission, $allowed, true), $user->can($permission), $role.': '.$permission);
        }

        $this->assertFalse($user->can('unknown.permission'));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function rolePermissions(): array
    {
        return [
            'administrator' => ['super_admin', [
                'delivery_zones.view', 'delivery_zones.create', 'delivery_zones.update', 'delivery_zones.delete',
                'branches.products.manage',
                'branches.view', 'branches.create', 'branches.update', 'branches.delete',
                'orders.view', 'orders.create', 'orders.update_status', 'orders.cancel',
                'orders.confirm', 'orders.start_preparing', 'orders.mark_ready', 'orders.dispatch', 'orders.complete',
                'products.view', 'products.create', 'products.update', 'products.delete',
                'categories.manage', 'offers.manage', 'customers.view', 'reports.view', 'settings.manage',
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                'options.view', 'options.create', 'options.update', 'options.delete',
            ]],
            'manager' => ['manager', [
                'delivery_zones.view', 'delivery_zones.create', 'delivery_zones.update', 'delivery_zones.delete',
                'branches.products.manage',
                'branches.view', 'branches.create', 'branches.update', 'branches.delete',
                'orders.view', 'orders.create', 'orders.update_status', 'orders.cancel',
                'orders.confirm', 'orders.start_preparing', 'orders.mark_ready', 'orders.dispatch', 'orders.complete',
                'products.view', 'products.create', 'products.update', 'products.delete',
                'categories.manage', 'offers.manage', 'customers.view', 'reports.view',
                'categories.view', 'categories.create', 'categories.update', 'categories.delete',
                'options.view', 'options.create', 'options.update', 'options.delete',
            ]],
            'cashier' => ['cashier', ['orders.view', 'orders.create', 'orders.confirm', 'orders.complete', 'products.view', 'customers.view']],
            'kitchen' => ['kitchen', ['orders.view', 'orders.update_status', 'orders.start_preparing', 'orders.mark_ready']],
            'customer' => ['customer', []],
        ];
    }

    public function test_seeding_twice_preserves_assignments_without_duplicate_roles_or_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('cashier');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('permissions', 35);
        $this->assertDatabaseCount('model_has_roles', 1);
        $this->assertSame(['cashier'], $user->fresh()->getRoleNames()->all());
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
    }

    public function test_guests_and_users_without_roles_have_no_administrative_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();

        foreach (Permission::pluck('name') as $permission) {
            $this->assertFalse(Gate::allows($permission));
            $this->assertFalse($user->can($permission));
        }
    }

    public function test_permission_middleware_returns_403_for_customer_and_allows_super_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Route::middleware(['api', 'auth:sanctum', 'can:settings.manage'])
            ->get('/api/test-settings', fn () => response()->noContent());
        $customer = User::factory()->create()->assignRole('customer');
        $admin = User::factory()->create()->assignRole('super_admin');

        $this->getJson('/api/test-settings')->assertUnauthorized();
        $this->actingAs($customer)->getJson('/api/test-settings')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/test-settings')->assertNoContent();
    }

    public function test_registration_rolls_back_if_customer_role_is_missing(): void
    {
        Role::query()->count();

        try {
            app(RegisterUserAction::class)->handle([
                'name' => 'New user',
                'email' => 'rollback@example.com',
                'password' => 'secure-password',
            ]);
            $this->fail('Registration must fail when the required role is missing.');
        } catch (RoleDoesNotExist) {
            $this->assertDatabaseCount('users', 0);
        }
    }
}
