<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeliveryIssueCode;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Services\DeliveryAddressData;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryQuoteTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('districtMatches')]
    public function test_district_and_city_matching_are_normalized_without_fuzzy_matching(string $stored, string $city, string $district, bool $available): void
    {
        $zone = DeliveryZone::factory()->create();
        $zone->districts()->first()->update(['district_name' => $stored]);
        $this->postJson('/api/v1/delivery/quote', $this->payload($zone->branch, '80.00', compact('city', 'district')))
            ->assertOk()->assertJsonPath('data.available', $available)
            ->assertJsonPath('data.issue.code', $available ? null : 'ADDRESS_OUTSIDE_DELIVERY_AREA');
    }

    public static function districtMatches(): array
    {
        return [['المصيف', 'الرياض', 'المصيف', true], ['المصيف', ' الرياض ', '  المصيف  ', true],
            ['Al Masif', 'الرياض', 'AL   MASIF', true], ['المصيف', 'الرياض', 'المصيفف', false],
            ['المصيف', 'الرياض', 'النخيل', false], ['المصيف', 'جدة', 'المصيف', false]];
    }

    #[DataProvider('prices')]
    public function test_fee_minimum_and_free_threshold_use_exact_money(string $subtotal, ?string $threshold, bool $available, string $fee, string $total, string $remaining, bool $free): void
    {
        $zone = DeliveryZone::factory()->create(['delivery_fee' => '2.50', 'minimum_order' => '19.99', 'free_delivery_threshold' => $threshold]);
        $response = $this->postJson('/api/v1/delivery/quote', $this->payload($zone->branch, $subtotal))
            ->assertOk()->assertJsonPath('data.is_estimate', true)->assertJsonPath('data.available', $available)
            ->assertJsonPath('data.zone.id', $zone->uuid)->assertJsonPath('data.delivery_fee', $fee)
            ->assertJsonPath('data.estimated_total', $total)->assertJsonPath('data.remaining_amount', $remaining)
            ->assertJsonPath('data.minimum_order_met', $available)->assertJsonPath('data.free_delivery_applied', $free)
            ->assertJsonPath('data.current_subtotal', $subtotal)->assertJsonPath('data.estimated_delivery.min_minutes', 30);
        if (! $available) {
            $response->assertJsonPath('data.issue.code', 'MINIMUM_ORDER_NOT_MET');
        }
    }

    public static function prices(): array
    {
        return [['19.98', '100.00', false, '2.50', '22.48', '0.01', false],
            ['19.99', '100.00', true, '2.50', '22.49', '0.00', false],
            ['20.00', '100.00', true, '2.50', '22.50', '0.00', false],
            ['99.99', '100.00', true, '2.50', '102.49', '0.00', false],
            ['100.00', '100.00', true, '0.00', '100.00', '0.00', true],
            ['120.00', '100.00', true, '0.00', '120.00', '0.00', true],
            ['120.00', null, true, '2.50', '122.50', '0.00', false]];
    }

    #[DataProvider('radiusLocations')]
    public function test_radius_uses_unrounded_distance(?string $latitude, ?string $longitude, bool $available, ?string $code): void
    {
        $zone = DeliveryZone::factory()->radius()->create(['center_latitude' => '0.0000000', 'center_longitude' => '0.0000000', 'radius_km' => '1.00']);
        $this->postJson('/api/v1/delivery/quote', $this->payload($zone->branch, '80.00', [
            'city' => 'الرياض', 'district' => 'أي حي', 'latitude' => $latitude, 'longitude' => $longitude,
        ]))->assertOk()->assertJsonPath('data.available', $available)->assertJsonPath('data.issue.code', $code);
    }

    public static function radiusLocations(): array
    {
        return [['0', '0', true, null], ['0', '0.008992', true, null],
            ['0', '0.008994', false, 'ADDRESS_OUTSIDE_DELIVERY_AREA'],
            ['0', '1', false, 'ADDRESS_OUTSIDE_DELIVERY_AREA'],
            [null, null, false, 'ADDRESS_COORDINATES_REQUIRED']];
    }

    public function test_priority_then_district_then_id_select_the_winner(): void
    {
        $branch = Branch::factory()->create();
        $first = DeliveryZone::factory()->for($branch)->radius()->create(['priority' => 10]);
        $district = DeliveryZone::factory()->for($branch)->create(['priority' => 10]);
        $payload = $this->payload($branch, '80.00', ['city' => 'الرياض', 'district' => 'المصيف', 'latitude' => 24.7, 'longitude' => 46.7]);
        $this->postJson('/api/v1/delivery/quote', $payload)->assertOk()->assertJsonPath('data.zone.id', $district->uuid);
        $first->update(['priority' => 11]);
        $this->postJson('/api/v1/delivery/quote', $payload)->assertOk()->assertJsonPath('data.zone.id', $first->uuid);
        $first->update(['is_active' => false]);
        DeliveryZone::factory()->for($branch)->create(['priority' => 10]);
        $this->postJson('/api/v1/delivery/quote', $payload)->assertOk()->assertJsonPath('data.zone.id', $district->uuid);
        $district->delete();
        $this->postJson('/api/v1/delivery/quote', $payload)->assertOk()->assertJsonPath('data.available', true);
    }

    public function test_inactive_deleted_and_foreign_branch_zones_do_not_match(): void
    {
        $branch = Branch::factory()->create();
        DeliveryZone::factory()->for($branch)->inactive()->create();
        DeliveryZone::factory()->for($branch)->create()->delete();
        DeliveryZone::factory()->create(['priority' => 999]);
        $this->postJson('/api/v1/delivery/quote', $this->payload($branch))->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.issue.code', 'ADDRESS_OUTSIDE_DELIVERY_AREA');
    }

    #[DataProvider('branchStates')]
    public function test_branch_order_types_are_independent(bool $active, bool $accepts, bool $pickup, bool $delivery, bool $expectedPickup, ?string $code): void
    {
        $branch = Branch::factory()->create(['is_active' => $active, 'accepts_orders' => $accepts, 'supports_pickup' => $pickup, 'supports_delivery' => $delivery]);
        DeliveryZone::factory()->for($branch)->create();
        $this->postJson('/api/v1/delivery/quote', $this->payload($branch))->assertOk()
            ->assertJsonPath('data.available', $code === null)->assertJsonPath('data.issue.code', $code);
        $service = app(DeliveryService::class);
        $this->assertSame($expectedPickup, $service->supportsOrderType($branch, OrderType::Pickup));
        $this->assertSame($code === null, $service->supportsOrderType($branch, OrderType::Delivery));
    }

    public static function branchStates(): array
    {
        return [[true, true, true, true, true, null], [true, true, false, true, false, null],
            [true, true, true, false, true, 'DELIVERY_NOT_AVAILABLE'],
            [true, false, true, true, false, 'BRANCH_NOT_ACCEPTING_ORDERS'],
            [false, true, true, true, false, 'BRANCH_NOT_ACCEPTING_ORDERS']];
    }

    public function test_dto_rejects_invalid_address_without_guessing(): void
    {
        $branch = Branch::factory()->create();
        $quote = app(DeliveryService::class)->calculateDelivery($branch, new DeliveryAddressData('الرياض', 'المصيف', '24', null), '80.00');
        $this->assertSame(DeliveryIssueCode::InvalidAddress, $quote->issueCode);
        $this->assertFalse($quote->isAvailable);
    }

    public function test_preview_validates_coordinate_pair_and_money_and_ignores_client_fee(): void
    {
        $zone = DeliveryZone::factory()->create();
        $payload = $this->payload($zone->branch);
        $this->postJson('/api/v1/delivery/quote', array_replace($payload, ['subtotal' => '-1']))->assertUnprocessable();
        $this->postJson('/api/v1/delivery/quote', array_replace($payload, ['subtotal' => '1.001']))->assertUnprocessable();
        $payload['address']['latitude'] = 24;
        $this->postJson('/api/v1/delivery/quote', $payload)->assertUnprocessable();
        unset($payload['address']['latitude']);
        $this->postJson('/api/v1/delivery/quote', $payload + ['delivery_fee' => '0.00', 'estimated_total' => '0.00', 'zone_uuid' => 'fake'])
            ->assertOk()->assertJsonPath('data.delivery_fee', '5.00')->assertJsonPath('data.estimated_total', '85.00');
    }

    public function test_zone_loading_does_not_query_each_district_separately(): void
    {
        $branch = Branch::factory()->create();
        DeliveryZone::factory()->count(10)->for($branch)->create();
        DB::enableQueryLog();
        $this->postJson('/api/v1/delivery/quote', $this->payload($branch))->assertOk();
        $districtQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "delivery_zone_districts"'));
        DB::disableQueryLog();
        $this->assertCount(1, $districtQueries);
    }

    private function payload(Branch $branch, string $subtotal = '80.00', ?array $address = null): array
    {
        return ['branch_uuid' => $branch->uuid, 'subtotal' => $subtotal, 'address' => $address ?? ['city' => 'الرياض', 'district' => 'المصيف']];
    }
}
