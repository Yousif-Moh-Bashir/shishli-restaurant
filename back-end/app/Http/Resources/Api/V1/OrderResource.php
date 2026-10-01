<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'order_number' => $this->order_number, 'type' => $this->type->value, 'status' => $this->status->value,
            'payment' => ['method' => $this->payment_method->value, 'status' => $this->payment_status->value],
            'customer' => ['name' => $this->customer_name, 'phone' => $this->customer_phone, 'email' => $this->customer_email],
            'branch' => $this->whenLoaded('branch', fn (): array => ['id' => $this->branch->uuid, 'name' => $this->branch->name]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'address' => new OrderAddressResource($this->whenLoaded('address')),
            'pricing' => ['subtotal' => $this->subtotal, 'delivery_fee' => $this->delivery_fee, 'discount_total' => $this->discount_total,
                'tax_total' => $this->tax_total, 'total' => $this->total, 'currency' => $this->currency],
            'notes' => $this->customer_notes, 'placed_at' => $this->placed_at->toIso8601String(),
            'timeline' => [
                'placed_at' => $this->placed_at->toIso8601String(),
                'confirmed_at' => $this->confirmed_at?->toIso8601String(),
                'preparing_at' => $this->preparing_at?->toIso8601String(),
                'ready_at' => $this->ready_at?->toIso8601String(),
                'out_for_delivery_at' => $this->out_for_delivery_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            'cancellation' => $this->when($this->status === OrderStatus::Cancelled, fn (): array => [
                'reason' => $this->cancellation_reason, 'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ]),
        ];
    }
}
