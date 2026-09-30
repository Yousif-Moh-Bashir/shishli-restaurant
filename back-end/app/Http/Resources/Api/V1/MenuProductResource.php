<?php

namespace App\Http\Resources\Api\V1;

use App\Services\PricingService;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (new ProductResource($this->product))->toArray($request);
        $data['price'] = app(PricingService::class)->getProductPriceForBranch($this->product, $this->branch, $this->resource);
        $data['has_price_override'] = $this->price_override !== null;
        $data['is_available'] = $data['available'] = app(ProductAvailabilityService::class)->isAvailable($this->product, $this->branch, $this->resource);
        $data['availability_message'] = $data['is_available'] ? null : 'غير متوفر حاليًا';

        return $data;
    }
}
