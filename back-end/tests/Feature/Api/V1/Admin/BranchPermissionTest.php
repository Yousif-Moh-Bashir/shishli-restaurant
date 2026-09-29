<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchPermissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, bool, string}> */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', false, 'branches.view'],
            'store' => ['POST', false, 'branches.create'],
            'show' => ['GET', true, 'branches.view'],
            'put' => ['PUT', true, 'branches.update'],
            'patch' => ['PATCH', true, 'branches.update'],
            'destroy' => ['DELETE', true, 'branches.delete'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_guest_cannot_access_branch_admin(string $method, bool $individual, string $permission): void
    {
        $branch = Branch::factory()->create();
        $this->json($method, '/api/v1/admin/branches'.($individual ? '/'.$branch->uuid : ''))
            ->assertUnauthorized()->assertJsonPath('success', false);
    }

    #[DataProvider('endpoints')]
    public function test_other_permissions_do_not_allow_the_operation(string $method, bool $individual, string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $branch = Branch::factory()->create();
        $user = User::factory()->create()->assignRole('customer');
        $user->givePermissionTo(array_values(array_diff([
            'branches.view', 'branches.create', 'branches.update', 'branches.delete', 'categories.manage',
        ], [$permission])));

        $this->withToken($user->createToken('test')->plainTextToken)
            ->json($method, '/api/v1/admin/branches'.($individual ? '/'.$branch->uuid : ''), ['name' => 'Changed', 'slug' => 'changed'])
            ->assertForbidden()->assertExactJson([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتنفيذ هذه العملية',
            ]);
        $this->assertSame($branch->name, $branch->fresh()->name);
        $this->assertDatabaseCount('branches', 1);
        $this->assertNotSoftDeleted($branch);
    }

    #[DataProvider('endpoints')]
    public function test_exact_permission_allows_operation_without_other_permissions(string $method, bool $individual, string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->json($method, '/api/v1/admin/branches'.($individual ? '/'.$branch->uuid : ''), ['name' => 'Changed', 'slug' => 'changed']);
        $response->assertStatus($method === 'POST' ? 201 : 200);

        if ($method === 'DELETE') {
            $this->assertSoftDeleted($branch);
        } elseif (in_array($method, ['PUT', 'PATCH'], true)) {
            $this->assertSame('Changed', $branch->fresh()->name);
        } elseif ($method === 'POST') {
            $this->assertDatabaseHas('branches', ['name' => 'Changed', 'slug' => 'changed']);
        } else {
            $response->assertJsonPath($individual ? 'data.id' : 'data.0.id', $branch->uuid);
        }
    }

    public function test_customer_without_permissions_cannot_access_branch_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('customer');
        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/admin/branches')->assertForbidden();
    }
}
