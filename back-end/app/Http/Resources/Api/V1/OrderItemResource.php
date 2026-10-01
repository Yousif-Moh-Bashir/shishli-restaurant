<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'product' => ['id' => $this->product_uuid, 'name' => $this->product_name, 'sku' => $this->product_sku],
            'quantity' => $this->quantity, 'options' => OrderItemOptionResource::collection($this->whenLoaded('options')),
            'pricing' => ['base_price' => $this->base_price, 'options_total' => $this->options_total, 'unit_price' => $this->unit_price, 'line_total' => $this->line_total]];
    }
}
