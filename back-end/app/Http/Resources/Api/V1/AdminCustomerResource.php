<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class AdminCustomerResource extends CustomerProfileResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), ['recent_orders' => OrderResource::collection($this->whenLoaded('orders'))]);
    }
}
