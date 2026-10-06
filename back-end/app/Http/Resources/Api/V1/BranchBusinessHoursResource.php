<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DayOfWeek;
use App\Services\RestaurantSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchBusinessHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('businessHours');
        $days = [];
        foreach (DayOfWeek::cases() as $weekday) {
            $day = $weekday->value;
            $name = strtolower($weekday->name);
            $periods = $this->businessHours->where('day_of_week', $day)->sortBy('opens_at')->map(fn ($hour): array => ['opens_at' => substr($hour->opens_at, 0, 5), 'closes_at' => substr($hour->closes_at, 0, 5)])->values()->all();
            $days[] = ['day_of_week' => $day, 'day_name' => $name, 'periods' => $periods];
        }

        return ['timezone' => app(RestaurantSettingsService::class)->getString('restaurant.timezone'), 'days' => $days];
    }
}
