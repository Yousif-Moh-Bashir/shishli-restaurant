<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'method' => $this->method->value, 'provider' => $this->provider,
            'status' => $this->status->value, 'amount' => $this->amount, 'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(), 'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), 'refunded_amount' => $this->refunded_amount,
            'failure_code' => $this->when($this->status === PaymentStatus::Failed, 'PAYMENT_FAILED'),
            'created_at' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn (): array => ['id' => $this->order->uuid, 'order_number' => $this->order->order_number])];
    }
}
