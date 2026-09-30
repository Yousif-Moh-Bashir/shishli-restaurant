<?php

namespace App\Http\Resources\Api\V1;

use App\Services\PricingService;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product' => new ProductResource($this->product),
            'branch' => ['id' => $this->branch->uuid, 'name' => $this->branch->name],
            'price_override' => $this->price_override,
            'effective_price' => app(PricingService::class)->getProductPriceForBranch($this->product, $this->branch, $this->resource),
            'is_available' => $this->is_available,
            'global_is_available' => $this->product->is_available,
            'effective_is_available' => app(ProductAvailabilityService::class)->isAvailable($this->product, $this->branch, $this->resource),
        ];
    }
}
