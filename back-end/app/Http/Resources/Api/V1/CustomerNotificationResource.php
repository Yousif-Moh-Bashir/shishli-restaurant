<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'type' => $this->type->value, 'status' => $this->status->value,
            'title' => $this->title, 'body' => $this->body, 'created_at' => $this->created_at?->toISOString(),
            'occurred_at' => $this->occurred_at?->toISOString(), 'read_at' => $this->read_at?->toISOString(),
            'order' => ['id' => $this->order->uuid, 'number' => $this->order->order_number, 'type' => $this->order->type->value]];
    }
}
