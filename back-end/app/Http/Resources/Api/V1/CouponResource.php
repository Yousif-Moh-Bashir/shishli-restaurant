<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'code' => $this->code, 'is_active' => $this->is_active, 'starts_at' => $this->starts_at?->toISOString(), 'ends_at' => $this->ends_at?->toISOString(), 'usage_limit' => $this->usage_limit, 'usage_limit_per_user' => $this->usage_limit_per_user, 'used_count' => $this->used_count];
    }
}
