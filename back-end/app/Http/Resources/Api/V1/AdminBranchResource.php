<?php

namespace App\Http\Resources\Api\V1;

use App\Services\BranchAvailabilityService;
use Illuminate\Http\Request;

class AdminBranchResource extends BranchResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['products_count' => $this->whenCounted('products'), 'delivery_zones_count' => $this->whenCounted('deliveryZones'),
            'business_hours' => $this->whenLoaded('businessHours', fn (): BranchBusinessHoursResource => new BranchBusinessHoursResource($this->resource)),
            'availability' => $this->when($this->resource->relationLoaded('businessHours') && $this->resource->relationLoaded('closures'), fn (): array => app(BranchAvailabilityService::class)->evaluate($this->resource)->toArray())];
    }
}
