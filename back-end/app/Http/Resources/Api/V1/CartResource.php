<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Cart;
use App\Services\BranchAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function __construct(Cart $cart, private ?string $plainToken = null)
    {
        parent::__construct($cart);
    }

    public function toArray(Request $request): array
    {
        $availability = app(BranchAvailabilityService::class)->evaluate($this->branch);

        return [
            'id' => $this->uuid,
            'token' => $this->when($this->plainToken !== null, fn () => $this->plainToken),
            'status' => $this->status->value,
            'branch' => ['id' => $this->branch->uuid, 'name' => $this->branch->name,
                'is_active' => $this->branch->is_active && ! $this->branch->trashed(), 'accepts_orders' => $this->branch->accepts_orders],
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'coupon' => $this->resource->coupon !== null ? ['code' => $this->resource->coupon->code] : null,
            'promotions' => array_map(fn ($result): array => $result->summary(), $this->resource->discountResult?->appliedPromotions ?? []),
            'summary' => ['items_count' => $this->items->count(), 'quantity_total' => $this->items->sum('quantity'),
                'subtotal' => $this->subtotal, 'discount_total' => $this->discount_total, 'total' => $this->total],
            'can_checkout' => $availability->acceptsOrders && $this->items->isNotEmpty() && $this->resource->issues === []
                && $this->items->every(fn ($item): bool => $item->issues === []),
            'issues' => $this->resource->issues,
            'availability' => new BranchAvailabilityResource($availability),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
