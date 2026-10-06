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
        if (! $kitchen) {
            $data['status_history'] = OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory'));
        }

        return $data;
    }
}
