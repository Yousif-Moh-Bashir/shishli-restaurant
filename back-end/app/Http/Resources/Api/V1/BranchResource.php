<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DeliveryZoneType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            'name' => $this->name,
            'slug' => $this->slug,

            'contact' => [
                'phone' => $this->phone,
                'whatsapp' => $this->whatsapp,
            ],

            'location' => [
                'city' => $this->city,
                'district' => $this->district,
                'address' => $this->address,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],

            'is_active' => $this->is_active,
            'accepts_orders' => $this->accepts_orders,
            'supports_pickup' => $this->supports_pickup,
            'supports_delivery' => $this->supports_delivery,

            'delivery_coverage' => $this->whenLoaded('deliveryZones', fn (): array => [
                'city' => $this->city,
                'districts' => $this->deliveryZones
                    ->where('type', DeliveryZoneType::District)
                    ->flatMap(fn ($zone) => $zone->districts->pluck('district_name'))
                    ->unique()->values()->all(),
                'has_radius_zones' => $this->deliveryZones->contains('type', DeliveryZoneType::Radius),
            ]),

            'sort_order' => $this->sort_order,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
