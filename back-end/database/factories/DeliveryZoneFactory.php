<?php

namespace Database\Factories;

use App\Enums\DeliveryZoneType;
use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'name' => 'المصيف والمناطق القريبة', 'type' => DeliveryZoneType::District,
            'is_active' => true, 'delivery_fee' => '5.00', 'minimum_order' => '20.00', 'free_delivery_threshold' => null,
            'estimated_min_minutes' => 30, 'estimated_max_minutes' => 45, 'priority' => 0,
            'center_latitude' => null, 'center_longitude' => null, 'radius_km' => null];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DeliveryZone $zone): void {
            if ($zone->type === DeliveryZoneType::District) {
                $zone->districts()->create(['district_name' => 'المصيف']);
            }
        });
    }

    public function district(): static
    {
        return $this->state(['type' => DeliveryZoneType::District, 'center_latitude' => null, 'center_longitude' => null, 'radius_km' => null]);
    }

    public function radius(): static
    {
        return $this->state(['type' => DeliveryZoneType::Radius, 'center_latitude' => '24.7000000', 'center_longitude' => '46.7000000', 'radius_km' => '5.00']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function freeDelivery(): static
    {
        return $this->state(['free_delivery_threshold' => '100.00']);
    }
}
