<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Admin\AdminOrderActions;
use Illuminate\Http\Request;

class AdminOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $kitchen = $actor->hasRole('kitchen') && ! $actor->hasAnyRole(['super_admin', 'manager', 'cashier']);
        $data = $kitchen ? (new KitchenOrderResource($this->resource))->toArray($request) : parent::toArray($request);
        $data['available_actions'] = app(AdminOrderActions::class)->forOrder($this->resource, $actor);
        $data['created_at'] = $this->created_at?->toIso8601String();
        if (! $kitchen) {
            $data['delivery'] = ['status' => $this->delivery_status?->value,
                'driver' => $this->whenLoaded('driver', fn () => $this->driver ? ['id' => $this->driver->uuid, 'name' => $this->driver->name] : null),
                'accepted_at' => $this->driver_accepted_at?->toISOString(),
                'history' => $this->whenLoaded('deliveryHistory', fn () => $this->deliveryHistory->map(fn ($entry): array => [
                    'from_status' => $entry->from_status, 'to_status' => $entry->to_status, 'actor' => $entry->actor?->name,
                    'driver' => $entry->driver?->name, 'reason' => $entry->reason, 'at' => $entry->created_at?->toISOString()])->all())];
            $data['status_history'] = OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory'));
        }

        return $data;
    }
}
