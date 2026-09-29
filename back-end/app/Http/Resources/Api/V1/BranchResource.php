<?php

namespace App\Http\Resources\Api\V1;

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

            'sort_order' => $this->sort_order,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
