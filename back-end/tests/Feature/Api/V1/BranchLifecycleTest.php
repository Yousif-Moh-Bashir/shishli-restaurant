<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function authenticateAdmin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('super_admin');
        $this->withToken($user->createToken('branch-tests')->plainTextToken);
    }

    public function test_all_eight_routes_complete_branch_lifecycle(): void
    {
        $this->authenticateAdmin();
        $uuid = $this->postJson('/api/v1/admin/branches', [
            'name' => 'فرع الاختبار', 'slug' => 'test-branch', 'phone' => '0551040122',
            'latitude' => '24.7135517', 'longitude' => '46.6752957',
        ])->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', true)->assertJsonPath('data.accepts_orders', true)
            ->assertJsonPath('data.sort_order', 0)->assertJsonPath('data.location.latitude', '24.7135517')
            ->json('data.id');
        $branch = Branch::where('uuid', $uuid)->sole();
        $this->assertDatabaseHas('branches', ['uuid' => $uuid, 'phone' => '0551040122']);
        foreach (['/api/v1/branches', '/api/v1/admin/branches'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.0.id', $uuid);
            $this->getJson($path.'/'.$uuid)->assertOk()->assertJsonPath('data.id', $uuid);
            $this->getJson($path.'/'.$branch->id)->assertNotFound();
            $this->getJson($path.'/'.$branch->slug)->assertNotFound();
        }
        $this->putJson('/api/v1/admin/branches/'.$uuid, ['name' => 'Updated', 'slug' => 'test-branch', 'city' => 'الرياض'])
            ->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->patchJson('/api/v1/admin/branches/'.$uuid, ['accepts_orders' => false])
            ->assertOk()->assertJsonPath('data.accepts_orders', false);
        $this->assertSame('Updated', $branch->fresh()->name);
        $this->assertFalse($branch->fresh()->accepts_orders);
        $this->deleteJson('/api/v1/admin/branches/'.$uuid)->assertOk()->assertJsonPath('success', true);
        $this->assertSoftDeleted($branch);
        foreach (['/api/v1/branches', '/api/v1/admin/branches'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson($path.'/'.$uuid)->assertNotFound();
        }
        $this->patchJson('/api/v1/admin/branches/'.$uuid, ['name' => 'Deleted'])->assertNotFound();
        $this->putJson('/api/v1/admin/branches/'.$uuid, ['name' => 'Deleted'])->assertNotFound();
        $this->deleteJson('/api/v1/admin/branches/'.$uuid)->assertNotFound();
    }

    public function test_public_menu_hides_inactive_and_deleted_branches_and_sorts_active_branches(): void
    {
        $last = Branch::factory()->create(['sort_order' => 10]);
        $first = Branch::factory()->create(['sort_order' => 1, 'accepts_orders' => false]);
        $hidden = Branch::factory()->create(['is_active' => false]);
        $deleted = Branch::factory()->create();
        $deleted->delete();
        $response = $this->getJson('/api/v1/branches')->assertOk()->assertJsonPath('success', true);
        $this->assertSame([$first->uuid, $last->uuid], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/branches/'.$first->uuid)->assertOk()->assertJsonPath('data.accepts_orders', false);
        $this->getJson('/api/v1/branches/'.$hidden->uuid)->assertNotFound();
        $this->getJson('/api/v1/branches/'.$deleted->uuid)->assertNotFound();
        $this->authenticateAdmin();
        $this->getJson('/api/v1/admin/branches')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/admin/branches/'.$hidden->uuid)->assertOk();
    }

    public function test_minimal_creation_generates_unique_slug_and_ignores_internal_fields(): void
    {
        $this->authenticateAdmin();
        $payload = ['name' => 'فرع جديد', 'id' => 999, 'uuid' => 'injected', 'deleted_at' => '2020-01-01'];
        $first = $this->postJson('/api/v1/admin/branches', $payload)->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/admin/branches', $payload)->assertCreated()->json('data');
        $this->assertNotSame($first['slug'], $second['slug']);
        $this->assertNotSame('injected', $first['id']);
        $this->assertDatabaseCount('branches', 2);
        $this->assertNull(Branch::first()->deleted_at);
        $this->assertNull(Branch::first()->phone);
        $this->assertNull(Branch::first()->address);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'name required' => ['name', ''],
            'name length' => ['name', str_repeat('a', 151)],
            'slug format' => ['slug', 'invalid/slug'],
            'phone type' => ['phone', 551040122],
            'phone length' => ['phone', str_repeat('1', 21)],
            'whatsapp type' => ['whatsapp', []],
            'city length' => ['city', str_repeat('a', 101)],
            'district length' => ['district', str_repeat('a', 101)],
            'address length' => ['address', str_repeat('a', 1001)],
            'latitude range' => ['latitude', 91],
            'longitude range' => ['longitude', -181],
            'coordinate type' => ['latitude', 'bad'],
            'active boolean' => ['is_active', 'yes'],
            'orders boolean' => ['accepts_orders', 2],
            'sort negative' => ['sort_order', -1],
            'sort fractional' => ['sort_order', 1.5],
            'sort overflow' => ['sort_order', 4294967296],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_store_and_update_reject_invalid_data(string $field, mixed $value): void
    {
        $this->authenticateAdmin();
        $branch = Branch::factory()->create();
        $this->postJson('/api/v1/admin/branches', array_replace(['name' => 'New'], [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('success', false)->assertJsonValidationErrors($field);
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/v1/admin/branches/'.$branch->uuid, [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('branches', 1);
    }

    public function test_slug_conflicts_and_null_updates_return_validation_errors(): void
    {
        $this->authenticateAdmin();
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();
        $this->postJson('/api/v1/admin/branches', ['name' => 'New', 'slug' => $first->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson('/api/v1/admin/branches/'.$second->uuid, ['slug' => $first->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson('/api/v1/admin/branches/'.$second->uuid, ['slug' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $first->delete();
        $this->postJson('/api/v1/admin/branches', ['name' => 'New', 'slug' => $first->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_seeder_updates_existing_branch_and_preserves_uuid(): void
    {
        $branch = Branch::factory()->create(['slug' => 'al-masif', 'is_active' => false]);
        $this->seed(BranchSeeder::class);
        $this->seed(BranchSeeder::class);
        $this->assertDatabaseCount('branches', 1);
        $this->assertSame('فرع المصيف', $branch->fresh()->name);
        $this->assertSame($branch->uuid, $branch->fresh()->uuid);
        $this->assertTrue($branch->fresh()->is_active);
        $this->assertSame('0551040122', $branch->fresh()->whatsapp);
    }
}
