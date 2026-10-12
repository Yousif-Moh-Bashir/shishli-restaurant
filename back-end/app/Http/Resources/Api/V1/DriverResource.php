<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'enabled' => $this->driver_enabled, 'available' => $this->driver_available, 'account_active' => $this->is_active,
            'branch' => $this->driverBranch ? ['id' => $this->driverBranch->uuid, 'name' => $this->driverBranch->name] : null,
            'current_order' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($order): array => [
                'id' => $order->uuid, 'order_number' => $order->order_number, 'delivery_status' => $order->delivery_status?->value])->first()),
            'delivered_count' => $this->delivered_count ?? 0, 'unsettled_cash' => $this->unsettled_cash ?? '0.00'];
    }
}
