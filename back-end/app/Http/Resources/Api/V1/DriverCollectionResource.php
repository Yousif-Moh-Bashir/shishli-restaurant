<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'order' => ['id' => $this->order->uuid, 'order_number' => $this->order->order_number],
            'amount' => $this->amount, 'currency' => $this->currency, 'collected_at' => $this->collected_at?->toISOString(),
            'settled_at' => $this->settled_at?->toISOString(),
            'settlement' => $this->settlement ? ['id' => $this->settlement->uuid, 'cashier' => $this->settlement->cashier?->name,
                'reason' => $this->settlement->reason] : null];
    }
}
