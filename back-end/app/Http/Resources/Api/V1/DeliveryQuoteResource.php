<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['is_estimate' => true, 'available' => $this->isAvailable,
            'zone' => $this->zoneUuid === null ? null : ['id' => $this->zoneUuid, 'name' => $this->zoneName],
            'subtotal' => $this->subtotal, 'current_subtotal' => $this->subtotal, 'delivery_fee' => $this->deliveryFee,
            'estimated_total' => $this->estimatedTotal, 'minimum_order' => $this->minimumOrder,
            'minimum_order_met' => $this->minimumOrderMet, 'remaining_amount' => $this->remainingAmount,
            'free_delivery_applied' => $this->freeDeliveryApplied,
            'estimated_delivery' => ['min_minutes' => $this->estimatedMinMinutes, 'max_minutes' => $this->estimatedMaxMinutes],
            'distance_km' => $this->distanceKm === null ? null : round($this->distanceKm, 3),
            'issue' => $this->issueCode === null ? null : ['code' => $this->issueCode->value, 'message' => $this->issueCode->message()]];
    }
}
