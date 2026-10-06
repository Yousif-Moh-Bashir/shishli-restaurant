<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Customers\CustomerStatisticsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestCustomerSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(['phone' => $this->customer_phone_normalized, 'latest_name' => $this->latest_name],
            CustomerStatisticsData::fromRow($this->resource)->toArray());
    }
}
