<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['recipient_name' => $this->recipient_name, 'phone' => $this->phone, 'city' => $this->city, 'district' => $this->district,
            'street' => $this->street, 'building_number' => $this->building_number, 'floor' => $this->floor, 'apartment' => $this->apartment,
            'landmark' => $this->landmark, 'notes' => $this->notes,
            'location' => ['latitude' => $this->latitude, 'longitude' => $this->longitude], 'delivery_zone_name' => $this->delivery_zone_name];
    }
}
