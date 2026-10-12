<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description,
            'type' => $this->type->value, 'application_type' => $this->application_type->value,
            'discount' => ['type' => $this->discount_type->value, 'value' => $this->discount_value, 'maximum' => $this->maximum_discount],
            'minimum_subtotal' => $this->minimum_subtotal,
            'first_order_only' => $this->first_order_only,
            'is_registration_offer' => $this->is_registration_offer,
            'starts_at' => $this->starts_at?->toISOString(), 'ends_at' => $this->ends_at?->toISOString(),
            'is_active' => $this->is_active, 'priority' => $this->priority, 'is_stackable' => $this->is_stackable,
            'limits' => ['usage_limit' => $this->usage_limit, 'usage_limit_per_user' => $this->usage_limit_per_user, 'used_count' => $this->used_count],
            'branches' => $this->branches->map(fn ($target): array => ['id' => $target->uuid, 'name' => $target->name]),
            'products' => $this->products->map(fn ($target): array => ['id' => $target->uuid, 'name' => $target->name]),
            'categories' => $this->categories->map(fn ($target): array => ['id' => $target->uuid, 'name' => $target->name])];
    }
}
