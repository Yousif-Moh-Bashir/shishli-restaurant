<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Customers\CustomerStatisticsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCustomerSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statistics = CustomerStatisticsData::fromRow($this->resource);

        return ['id' => $this->uuid, 'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email,
            'is_active' => $this->is_active, 'orders_count' => $statistics->ordersCount,
            'total_spent' => $statistics->totalSpent, 'last_order_at' => $statistics->lastOrderAt,
            'registered_at' => $this->created_at?->toISOString()];
    }
}
