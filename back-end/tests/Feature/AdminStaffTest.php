<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStaffTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'New Staff', 'phone' => '+249912345678', 'password' => 'SecureStaff123', 'roles' => ['cashier']], $overrides);
    }

    public function test_staff_creation_supports_phone_only_hashes_password_and_records_safe_audit(): void
    {
        $actor = $this->actor();
        $response = $this->postJson('/api/v1/admin/staff', $this->payload())->assertCreated()->assertJsonPath('data.roles', ['cashier']);
        $user = User::where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertNull($user->email);
        $this->assertTrue(Hash::check('SecureStaff123', $user->password));
        $log = AdminActivityLog::sole();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($user->uuid, $log->subject_uuid);
        $this->assertSame('staff.create', $log->action);
        $this->assertStringNotContainsString('SecureStaff123', json_encode($log->new_values));
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_new_staff_uses_existing_phone_login(): void
    {
        $this->actor();
        $this->postJson('/api/v1/admin/staff', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['phone' => '+249912345678', 'password' => 'SecureStaff123'])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_manager_can_create_operational_staff(): void
    {
        $this->actor('manager');
        $this->postJson('/api/v1/admin/staff', $this->payload())->assertCreated();
    }

    public function test_manager_cannot_create_super_admin(): void
    {
        $this->actor('manager');
        $this->postJson('/api/v1/admin/staff', $this->payload(['roles' => ['super_admin']]))->assertForbidden();
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }

    public function test_manager_cannot_modify_super_admin(): void
    {
        $super = User::factory()->create()->assignRole('super_admin');
        $this->actor('manager');
        $this->patchJson('/api/v1/admin/staff/'.$super->uuid, ['name' => 'Override'])->assertForbidden();
    }

    public function test_staff_update_revokes_tokens_on_password_and_role_changes(): void
    {
        $this->actor();
        $user = User::factory()->create()->assignRole('cashier');
        $user->createToken('staff');
        $this->patchJson('/api/v1/admin/staff/'.$user->uuid, ['password' => 'ChangedSecure123', 'roles' => ['kitchen']])->assertOk()->assertJsonPath('data.roles', ['kitchen']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('ChangedSecure123', $user->fresh()->password));
        $log = AdminActivityLog::sole();
        $this->assertTrue($log->new_values['password_changed']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_deactivation_revokes_tokens_and_activation_allows_new_login(): void
    {
        $this->actor();
        $user = User::factory()->create()->assignRole('cashier');
        $token = $user->createToken('staff')->plainTextToken;
        $this->postJson('/api/v1/admin/staff/'.$user->uuid.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/admin/staff/'.$user->uuid.'/activate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseCount('admin_activity_logs', 2);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_self_deactivation_is_rejected(): void
    {
        $actor = $this->actor();
        $this->postJson('/api/v1/admin/staff/'.$actor->uuid.'/deactivate')->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_last_active_super_admin_cannot_lose_role(): void
    {
        $actor = $this->actor();
        $this->patchJson('/api/v1/admin/staff/'.$actor->uuid, ['roles' => ['manager']])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_another_active_super_admin_allows_role_removal(): void
    {
        $actor = $this->actor();
        User::factory()->create()->assignRole('super_admin');
        $this->patchJson('/api/v1/admin/staff/'.$actor->uuid, ['roles' => ['manager']])->assertOk()->assertJsonPath('data.roles', ['manager']);
    }

    public function test_inactive_super_admin_does_not_satisfy_last_active_guard(): void
    {
        $actor = $this->actor();
        User::factory()->create(['is_active' => false])->assignRole('super_admin');
        $this->patchJson('/api/v1/admin/staff/'.$actor->uuid, ['roles' => ['cashier']])->assertUnprocessable();
    }

    public function test_custom_permission_actor_cannot_disable_last_super_admin(): void
    {
        $super = User::factory()->create()->assignRole('super_admin');
        $actor = $this->actor('manager');
        $actor->givePermissionTo('staff.deactivate');
        $this->postJson('/api/v1/admin/staff/'.$super->uuid.'/deactivate')->assertForbidden();
    }

    public function test_customer_is_excluded_from_staff_list_and_detail(): void
    {
        $actor = $this->actor();
        $customer = User::factory()->create()->assignRole('customer');
        $this->getJson('/api/v1/admin/staff')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $actor->uuid);
        $this->getJson('/api/v1/admin/staff/'.$customer->uuid)->assertNotFound();
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_or_privileged_staff_input_is_rejected(array $overrides, string $field): void
    {
        $this->actor();
        $this->postJson('/api/v1/admin/staff', $this->payload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFields(): array
    {
        return [[['roles' => ['customer']], 'roles.0'], [['roles' => ['unknown']], 'roles.0'], [['roles' => ['cashier', 'cashier']], 'roles.0'], [['phone' => '0912345678'], 'phone'], [['password' => 'short'], 'password'], [['is_active' => false], 'is_active'], [['permissions' => ['settings.update']], 'permissions'], [['id' => 1], 'id'], [['password' => 'lowercase12345'], 'password']];
    }

    public function test_duplicate_phone_and_email_are_rejected(): void
    {
        $this->actor();
        User::factory()->create(['phone' => '+249912345678', 'email' => 'staff@example.com']);
        $this->postJson('/api/v1/admin/staff', $this->payload(['email' => 'staff@example.com']))->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
    }

    public function test_staff_filters_and_sort_are_validated_and_paginated(): void
    {
        $this->actor();
        User::factory()->create(['name' => 'Cook Person'])->assignRole('kitchen');
        $this->getJson('/api/v1/admin/staff?search=Cook&role=kitchen&sort=name&direction=asc&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Cook Person')->assertJsonPath('meta.total', 1);
    }

    public function test_actor_cannot_take_over_staff_with_higher_permissions(): void
    {
        $target = User::factory()->create()->assignRole('manager');
        $actor = User::factory()->create()->assignRole('cashier');
        $actor->givePermissionTo('staff.update');
        $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/admin/staff/'.$target->uuid, ['password' => 'TakeoverSecure123'])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_staff_creation(): void
    {
        $this->actor();
        $this->mock(AdminActivityService::class)->shouldReceive('values')->andReturn(['name' => 'Staff']);
        $this->app->make(AdminActivityService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/v1/admin/staff', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_activity_logs', 0);
    }
}
