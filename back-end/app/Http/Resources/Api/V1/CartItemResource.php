<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'product' => ['id' => $this->product->uuid, 'name' => $this->product->name, 'slug' => $this->product->slug,
                'primary_image' => new ProductImageResource($this->product->primaryImage)],
            'quantity' => $this->quantity,
            'options' => CartItemOptionResource::collection($this->whenLoaded('options')),
            'pricing' => ['base_price' => $this->base_price, 'options_total' => $this->options_total,
                'unit_price' => $this->unit_price, 'line_total' => $this->line_total],
            'is_available' => $this->resource->issues === [],
            'issues' => $this->resource->issues,
        ];
    }
}
