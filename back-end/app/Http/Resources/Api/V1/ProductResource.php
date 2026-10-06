<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Branch;
use App\Models\BranchInventory;
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
        $requiresBranch = $this->tracks_inventory && ! $request->is('api/v1/admin/*');

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
            'tracks_inventory' => (bool) $this->tracks_inventory,
            'available' => $requiresBranch ? null : $this->is_available,
            'is_available' => $requiresBranch ? null : $this->is_available,
            'availability_reason' => $requiresBranch ? 'BRANCH_REQUIRED' : null,
            'option_groups' => ProductOptionGroupResource::collection($this->whenLoaded('optionGroups')),
            'branches' => $this->whenLoaded('branches', fn (): array => $this->branches->map(fn (Branch $branch): array => ['id' => $branch->uuid, 'name' => $branch->name, 'price_override' => $branch->pivot->price_override, 'is_available' => (bool) $branch->pivot->is_available])->all()),
            'inventory_summary' => $this->whenLoaded('inventories', fn (): array => $this->inventories->map(fn (BranchInventory $inventory): array => ['id' => $inventory->uuid, 'branch_uuid' => $inventory->branch->uuid, 'on_hand' => $inventory->on_hand, 'reserved' => $inventory->reserved, 'available' => $inventory->available(), 'is_tracking' => (bool) $inventory->is_tracking])->all()),
            'availability_message' => $this->is_available ? null : 'غير متوفر حاليًا',
            'sort_order' => $this->sort_order,
            'preparation_time' => $this->preparation_time,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
