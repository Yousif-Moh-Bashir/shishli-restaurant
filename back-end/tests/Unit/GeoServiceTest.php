<?php

namespace Tests\Unit;

use App\Services\GeoService;
use PHPUnit\Framework\TestCase;

class GeoServiceTest extends TestCase
{
    public function test_same_coordinates_have_zero_distance(): void
    {
        $this->assertEqualsWithDelta(0.0, (new GeoService)->distanceInKilometers(24.7, 46.7, 24.7, 46.7), 0.000001);
    }

    public function test_one_degree_at_equator_is_about_111_195_km(): void
    {
        $this->assertEqualsWithDelta(111.195, (new GeoService)->distanceInKilometers(0, 0, 0, 1), 0.001);
    }

    public function test_london_to_paris_is_about_343_6_km(): void
    {
        $this->assertEqualsWithDelta(343.6, (new GeoService)->distanceInKilometers(51.5074, -0.1278, 48.8566, 2.3522), 0.1);
    }

    public function test_antipodal_points_produce_finite_distance(): void
    {
        $this->assertEqualsWithDelta(20015.114, (new GeoService)->distanceInKilometers(0, 0, 0, 180), 0.01);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GeoService)->distanceInKilometers(91,0,0,0);
    }
}
