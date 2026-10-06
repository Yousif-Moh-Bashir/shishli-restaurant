<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicPromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'description' => $this->description, 'type' => $this->type->value,
            'application_type' => $this->application_type->value,
            'discount' => ['type' => $this->discount_type->value, 'value' => $this->discount_value, 'maximum' => $this->maximum_discount],
            'minimum_subtotal' => $this->minimum_subtotal, 'starts_at' => $this->starts_at?->toISOString(), 'ends_at' => $this->ends_at?->toISOString()];
    }
}
