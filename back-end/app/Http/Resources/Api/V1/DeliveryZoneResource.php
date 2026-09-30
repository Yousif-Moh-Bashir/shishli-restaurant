<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DeliveryZoneType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'type' => $this->type->value, 'is_active' => $this->is_active,
            'delivery_fee' => $this->delivery_fee, 'minimum_order' => $this->minimum_order, 'free_delivery_threshold' => $this->free_delivery_threshold,
            'estimated_min_minutes' => $this->estimated_min_minutes, 'estimated_max_minutes' => $this->estimated_max_minutes, 'priority' => $this->priority,
            'radius' => $this->when($this->type === DeliveryZoneType::Radius, fn (): array => [
                'center_latitude' => $this->center_latitude, 'center_longitude' => $this->center_longitude, 'radius_km' => $this->radius_km]),
            'districts' => $this->when($this->type === DeliveryZoneType::District, fn (): array => $this->districts->pluck('district_name')->all())];
    }
}
