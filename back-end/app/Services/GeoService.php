<?php

namespace App\Services;

use InvalidArgumentException;

class GeoService
{
    /** Straight-line great-circle distance, never driving distance. */
    public function distanceInKilometers(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        foreach ([$latitude1, $latitude2] as $latitude) {
            if (! is_finite($latitude) || abs($latitude) > 90) {
                throw new InvalidArgumentException('Invalid latitude.');
            }
        }
        foreach ([$longitude1, $longitude2] as $longitude) {
            if (! is_finite($longitude) || abs($longitude) > 180) {
                throw new InvalidArgumentException('Invalid longitude.');
            }
        }
        $latDelta = deg2rad($latitude2 - $latitude1);
        $lonDelta = deg2rad($longitude2 - $longitude1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($lonDelta / 2) ** 2;

        return 6371.0088 * 2 * asin(sqrt(max(0.0, min(1.0, $a))));
    }
}
