<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'inventory_uuid' => $this->inventory->uuid,
            'order_uuid' => $this->order->uuid, 'order_item_uuid' => $this->orderItem->uuid,
            'quantity' => $this->quantity, 'status' => $this->status->value,
            'reserved_at' => $this->reserved_at?->toISOString(), 'consumed_at' => $this->consumed_at?->toISOString(),
            'released_at' => $this->released_at?->toISOString()];
    }
}
