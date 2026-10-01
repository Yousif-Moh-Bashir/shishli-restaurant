<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'type' => $this->type->value, 'status' => $this->status->value,
            'amount' => $this->amount, 'provider_reference' => $this->provider_reference,
            'processed_at' => $this->processed_at?->toIso8601String()];
    }
}
