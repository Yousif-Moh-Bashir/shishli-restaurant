<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['order' => $this->resource->order, 'progress' => $this->resource->progress,
            'timeline' => OrderTrackingTimelineResource::collection($this->resource->timeline),
            'estimates' => $this->resource->estimates, 'branch' => $this->resource->branch, 'payment' => $this->resource->paymentSummary,
            'customer' => $this->resource->customerSafeSummary, 'items' => $this->resource->items, 'summary' => $this->resource->summary,
            'destination' => $this->resource->destination, 'last_updated_at' => $this->resource->lastUpdatedAt];
    }
}
