<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'from_status' => $this->from_status?->value, 'to_status' => $this->to_status->value,
            'source' => $this->source->value,
            'changed_by' => $this->whenLoaded('changedBy', fn (): array => ['id' => $this->changedBy->uuid, 'name' => $this->changedBy->name]),
            'note' => $this->note, 'created_at' => $this->created_at->toIso8601String()];
    }
}
