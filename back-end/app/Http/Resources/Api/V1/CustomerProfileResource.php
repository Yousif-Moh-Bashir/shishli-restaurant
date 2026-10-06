<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'uuid' => $this->uuid,
            'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'phone_verified' => $this->phone_verified_at !== null,
            'is_active' => $this->is_active, 'statistics' => $this->customer_statistics->toArray(),
            'default_address' => new CustomerAddressResource($this->whenLoaded('defaultAddress')),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
