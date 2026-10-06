<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'uuid' => $this->uuid, 'branch' => ['uuid' => $this->branch->uuid, 'name' => $this->branch->name],
            'product' => ['uuid' => $this->product->uuid, 'name' => $this->product->name, 'tracks_inventory' => (bool) $this->product->tracks_inventory],
            'stock' => ['on_hand' => $this->on_hand, 'reserved' => $this->reserved, 'available' => $this->resource->available()],
            'low_stock_threshold' => $this->low_stock_threshold, 'tracking' => $this->is_tracking,
            'recent_movements' => StockMovementResource::collection($this->whenLoaded('recentMovements')),
            'is_low_stock' => (bool) $this->product->tracks_inventory && $this->resource->isLowStock(),
            'is_out_of_stock' => (bool) $this->product->tracks_inventory && $this->resource->isOutOfStock(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
