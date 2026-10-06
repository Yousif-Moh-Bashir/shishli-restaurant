<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(string $role = 'super_admin'): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_roles_have_stable_public_names_and_grouped_permissions(): void
    {
        $this->actor();
        $roles = $this->getJson('/api/v1/admin/roles')->assertOk()->assertJsonCount(5, 'data')->json('data');
        foreach ($roles as $role) {
            $this->assertSame($role['name'], $role['id']);
        } $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonPath('data.staff', ['staff.activate', 'staff.create', 'staff.deactivate', 'staff.update', 'staff.view']);
    }

    public function test_super_admin_can_update_operational_permissions_and_cached_authorization_changes(): void
    {
        $actor = $this->actor();
        $cashier = User::factory()->create()->assignRole('cashier');
        $this->assertTrue($cashier->can('products.view'));
        $this->putJson('/api/v1/admin/roles/cashier/permissions', ['permissions' => ['orders.view']])->assertOk()->assertJsonPath('data.permissions', ['orders.view']);
        $this->assertFalse($cashier->fresh()->can('products.view'));
        $this->assertTrue($cashier->fresh()->can('orders.view'));
        $log = AdminActivityLog::sole();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame(['orders.view'], $log->new_values['permissions']);
    }

    #[DataProvider('protectedRoles')]
    public function test_system_roles_cannot_be_corrupted(string $role, array $permissions): void
    {
        $this->actor();
        $this->putJson('/api/v1/admin/roles/'.$role.'/permissions', ['permissions' => $permissions])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public static function protectedRoles(): array
    {
        return [['super_admin', []], ['super_admin', ['orders.view']], ['customer', ['orders.view']], ['customer', ['staff.create']]];
    }

    public function test_customer_permissions_can_only_remain_empty(): void
    {
        $this->actor();
        $this->putJson('/api/v1/admin/roles/customer/permissions', ['permissions' => []])->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_manager_cannot_update_permissions_even_with_direct_update_permission(): void
    {
        $user = $this->actor('manager');
        $user->givePermissionTo('roles.update_permissions');
        $this->putJson('/api/v1/admin/roles/kitchen/permissions', ['permissions' => []])->assertForbidden();
    }

    public function test_unknown_permission_is_rejected_without_modifying_role(): void
    {
        $this->actor();
        $before = Role::findByName('kitchen', 'web')->permissions->pluck('name')->all();
        $this->putJson('/api/v1/admin/roles/kitchen/permissions', ['permissions' => ['unknown.permission']])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->assertSame($before, Role::findByName('kitchen', 'web')->permissions->pluck('name')->all());
    }

    public function test_arbitrary_role_name_or_db_id_is_not_accepted(): void
    {
        $this->actor();
        $this->putJson('/api/v1/admin/roles/1/permissions', ['permissions' => []])->assertNotFound();
        $this->putJson('/api/v1/admin/roles/random/permissions', ['permissions' => []])->assertNotFound();
    }

    public function test_cashier_cannot_read_security_configuration(): void
    {
        $this->actor('cashier');
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/permissions')->assertForbidden();
    }

    public function test_reseeding_preserves_admin_changes_to_operational_roles(): void
    {
        $this->actor();
        $this->putJson('/api/v1/admin/roles/cashier/permissions', ['permissions' => ['orders.view']])->assertOk();
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(['orders.view'], Role::findByName('cashier', 'web')->permissions->pluck('name')->all());
        $this->assertSame(92, Role::findByName('super_admin', 'web')->permissions()->count());
    }

    public function test_role_audit_failure_rolls_back_permissions_and_customization(): void
    {
        $this->actor();
        $this->mock(AdminActivityService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->putJson('/api/v1/admin/roles/kitchen/permissions', ['permissions' => []])->assertStatus(500);
        $role = Role::findByName('kitchen', 'web');
        $this->assertFalse((bool) $role->permissions_customized);
        $this->assertTrue($role->hasPermissionTo('orders.start_preparing'));
    }
}
