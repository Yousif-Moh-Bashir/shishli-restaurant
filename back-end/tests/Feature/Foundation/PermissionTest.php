<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_cannot_access_admin_endpoint(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->registerAdminTestRoute();
        $customer = User::factory()->create()->assignRole('customer');
        $token = $customer->createToken('customer')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/test-permissions')
            ->assertForbidden()->assertExactJson([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتنفيذ هذه العملية',
            ]);
    }

    public function test_super_admin_can_access_admin_endpoint(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->registerAdminTestRoute();
        $admin = User::factory()->create()->assignRole('super_admin');
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/test-permissions')->assertNoContent();
    }

    private function registerAdminTestRoute(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'can:settings.manage'])
            ->get('/api/v1/admin/test-permissions', fn () => response()->noContent());
    }
}
