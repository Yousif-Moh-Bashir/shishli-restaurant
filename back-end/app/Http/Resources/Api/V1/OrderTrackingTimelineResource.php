<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderTrackingTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['status' => $this->resource['status'], 'label' => $this->resource['label'], 'state' => $this->resource['state'],
            'is_completed' => $this->resource['is_completed'], 'occurred_at' => $this->resource['occurred_at']];
    }
}
