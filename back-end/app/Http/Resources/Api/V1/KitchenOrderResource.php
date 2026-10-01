<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItem;
use App\Models\OrderItemOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'order_number' => $this->order_number, 'type' => $this->type->value, 'status' => $this->status->value,
            'placed_at' => $this->placed_at->toIso8601String(), 'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'customer_notes' => $this->customer_notes,
            'branch' => $this->whenLoaded('branch', fn (): array => ['id' => $this->branch->uuid, 'name' => $this->branch->name]),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->uuid, 'product' => ['id' => $item->product_uuid, 'name' => $item->product_name],
                'quantity' => $item->quantity, 'options' => $item->options->map(fn (OrderItemOption $option): array => [
                    'group' => ['id' => $option->option_group_uuid, 'name' => $option->option_group_name],
                    'value' => ['id' => $option->option_value_uuid, 'name' => $option->option_value_name],
                ])->all(),
            ])->all())];
    }
}
