<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'primary_image' => new ProductImageResource($this->whenLoaded('primaryImage')),
            'short_description' => $this->short_description,
            'description' => $this->description,
            'price' => $this->base_price,
            'base_price' => $this->base_price,
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'available' => $this->is_available,
            'is_available' => $this->is_available,
            'option_groups' => ProductOptionGroupResource::collection($this->whenLoaded('optionGroups')),
            'availability_message' => $this->is_available ? null : 'غير متوفر حاليًا',
            'sort_order' => $this->sort_order,
            'preparation_time' => $this->preparation_time,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
