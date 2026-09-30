<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DeliveryZoneSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('routes')]
    public function test_zone_routes_require_authentication_and_permission(string $method, bool $item): void
    {
        $zone = DeliveryZone::factory()->create();
        $url = $this->url($zone->branch).($item ? '/'.$zone->uuid : '');
        $this->json($method, $url, $this->payload())->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create(), 'sanctum')->json($method, $url, $this->payload())->assertForbidden();
    }

    public static function routes(): array
    {
        return [['GET', false], ['POST', false], ['GET', true], ['PATCH', true], ['DELETE', true]];
    }

    public function test_zone_crud_type_switching_and_scoped_binding(): void
    {
        $this->admin();
        $branch = Branch::factory()->create();
        $url = $this->url($branch);
        $uuid = $this->postJson($url, $this->payload())->assertCreated()->assertJsonPath('data.delivery_fee', '5.00')
            ->assertJsonMissingPath('data.branch_id')->json('data.id');
        $zone = DeliveryZone::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($branch->id, $zone->branch_id);
        $this->assertSame('المصيف', $zone->districts()->first()->normalized_name);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url.'/'.$uuid)->assertOk()->assertJsonPath('data.id', $uuid);
        $this->patchJson($url.'/'.$uuid, ['type' => 'radius'])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_zone_districts', 1);
        $this->patchJson($url.'/'.$uuid, ['type' => 'radius', 'center_latitude' => 24, 'center_longitude' => 46, 'radius_km' => '5.00'])
            ->assertOk()->assertJsonPath('data.radius.radius_km', '5.00')->assertJsonMissingPath('data.districts');
        $this->assertDatabaseCount('delivery_zone_districts', 0);
        $this->patchJson($url.'/'.$uuid, ['name' => 'اسم محدث'])->assertOk()->assertJsonPath('data.radius.radius_km', '5.00');
        $this->patchJson($url.'/'.$uuid, ['type' => 'district', 'districts' => ['النخيل']])->assertOk()->assertJsonMissingPath('data.radius');
        $this->assertNull($zone->fresh()->center_latitude);
        $this->assertSame('النخيل', $zone->districts()->first()->district_name);
        $wrong = $this->url(Branch::factory()->create()).'/'.$uuid;
        $this->getJson($wrong)->assertNotFound();
        $this->patchJson($wrong, ['name' => 'غير مسموح'])->assertNotFound();
        $this->deleteJson($wrong)->assertNotFound();
        $this->deleteJson($url.'/'.$uuid)->assertOk();
        $this->assertSoftDeleted($zone);
        $this->getJson($url.'/'.$uuid)->assertNotFound();
    }

    public function test_radius_can_be_created_directly(): void
    {
        $this->admin();
        $branch = Branch::factory()->create();
        $this->postJson($this->url($branch), array_replace($this->payload(), [
            'type' => 'radius', 'center_latitude' => 0, 'center_longitude' => 0, 'radius_km' => '0.01',
        ]))->assertCreated()->assertJsonPath('data.radius.center_latitude', '0.0000000');
        $this->assertDatabaseCount('delivery_zone_districts', 0);
    }

    #[DataProvider('invalidZones')]
    public function test_invalid_zone_data_returns_422(array $change): void
    {
        $this->admin();
        $this->postJson($this->url(Branch::factory()->create()), array_replace($this->payload(), $change))->assertUnprocessable();
        $this->assertDatabaseCount('delivery_zones', 0);
        $this->assertDatabaseCount('delivery_zone_districts', 0);
    }

    public static function invalidZones(): array
    {
        return [
            [['type' => 'polygon']], [['type' => 'radius']], [['type' => 'radius', 'center_latitude' => 24, 'center_longitude' => 46]],
            [['type' => 'radius', 'center_latitude' => 91, 'center_longitude' => 46, 'radius_km' => 5]],
            [['type' => 'radius', 'center_latitude' => 24, 'center_longitude' => 46, 'radius_km' => 0]],
            [['districts' => []]], [['districts' => ['المصيف', '  المصيف ']]], [['districts' => ['   ']]],
            [['delivery_fee' => '-1.00']], [['delivery_fee' => '1.001']], [['minimum_order' => '-1.00']],
            [['minimum_order' => '30.00', 'free_delivery_threshold' => '20.00']],
            [['estimated_min_minutes' => 60, 'estimated_max_minutes' => 30]], [['estimated_min_minutes' => 0]],
            [['priority' => -1]], [['free_delivery_threshold' => '3.333']], [['name' => null]],
        ];
    }

    public function test_patch_checks_existing_minimum_threshold_and_eta_and_rolls_back(): void
    {
        $this->admin();
        $zone = DeliveryZone::factory()->freeDelivery()->create();
        $url = $this->url($zone->branch).'/'.$zone->uuid;
        $this->patchJson($url, ['minimum_order' => '101.00'])->assertUnprocessable()->assertJsonValidationErrors('free_delivery_threshold');
        $this->patchJson($url, ['estimated_min_minutes' => 60])->assertUnprocessable()->assertJsonValidationErrors('estimated_max_minutes');
        $this->assertSame('20.00', $zone->fresh()->minimum_order);
        $this->assertSame(30, $zone->fresh()->estimated_min_minutes);
        $this->patchJson($url, ['minimum_order' => '101.00', 'free_delivery_threshold' => null])->assertOk();
        $this->assertNull($zone->fresh()->free_delivery_threshold);
    }

    public function test_branch_flags_are_editable_and_exposed(): void
    {
        $this->admin();
        $branch = Branch::factory()->create();
        $this->patchJson('/api/v1/admin/branches/'.$branch->uuid, ['supports_pickup' => false, 'supports_delivery' => false])
            ->assertOk()->assertJsonPath('data.supports_pickup', false)->assertJsonPath('data.supports_delivery', false);
        $this->assertFalse($branch->fresh()->supports_pickup);
        $this->assertFalse($branch->fresh()->supports_delivery);
    }

    public function test_delivery_seeder_is_repeatable_and_does_not_invent_coordinates(): void
    {
        $this->seed(BranchSeeder::class);
        $this->seed(DeliveryZoneSeeder::class);
        $this->seed(DeliveryZoneSeeder::class);
        $this->assertDatabaseCount('delivery_zones', 1);
        $this->assertDatabaseCount('delivery_zone_districts', 1);
        $zone = DeliveryZone::firstOrFail();
        $this->assertSame('5.00', $zone->delivery_fee);
        $this->assertSame('20.00', $zone->minimum_order);
        $this->assertSame('100.00', $zone->free_delivery_threshold);
        $this->assertNull($zone->center_latitude);
    }

    private function admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user, 'sanctum');
    }

    private function url(Branch $branch): string
    {
        return '/api/v1/admin/branches/'.$branch->uuid.'/delivery-zones';
    }

    private function payload(): array
    {
        return ['name' => 'منطقة المصيف', 'type' => 'district', 'districts' => ['المصيف'], 'delivery_fee' => '5.00', 'minimum_order' => '20.00'];
    }
}
