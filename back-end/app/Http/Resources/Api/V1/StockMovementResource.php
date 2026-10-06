<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'inventory_uuid' => $this->inventory->uuid,
            'branch_uuid' => $this->inventory->branch->uuid, 'product_uuid' => $this->inventory->product->uuid,
            'type' => $this->type->value, 'quantity' => $this->quantity,
            'on_hand_before' => $this->on_hand_before, 'on_hand_after' => $this->on_hand_after,
            'reserved_before' => $this->reserved_before, 'reserved_after' => $this->reserved_after,
            'reference_type' => $this->reference_type, 'reference_uuid' => $this->reference_uuid,
            'reason' => $this->reason, 'created_by' => $this->createdBy?->uuid, 'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString()];
    }
}
