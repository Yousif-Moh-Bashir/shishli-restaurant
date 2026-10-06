<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'starts_at' => $this->starts_at->toISOString(), 'ends_at' => $this->ends_at->toISOString(),
            'reason' => $this->reason, 'is_active' => $this->is_active, 'created_at' => $this->created_at->toISOString()];
    }
}
