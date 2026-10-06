<?php

namespace App\Services;

use App\Enums\BranchAvailabilityIssueCode;
use Carbon\CarbonImmutable;

final readonly class BranchAvailabilityData
{
    public function __construct(
        public bool $isActive, public bool $isOpen, public bool $acceptsOrders,
        public bool $pickupAvailable, public bool $deliveryAvailable,
        public ?BranchAvailabilityIssueCode $reasonCode, public ?string $message,
        public ?CarbonImmutable $nextOpeningAt,
        public ?BranchAvailabilityIssueCode $pickupReason = null, public ?BranchAvailabilityIssueCode $deliveryReason = null,
    ) {}

    public function toArray(): array
    {
        return ['is_active' => $this->isActive, 'is_open' => $this->isOpen, 'accepts_orders' => $this->acceptsOrders,
            'pickup' => ['available' => $this->pickupAvailable, 'reason_code' => $this->pickupReason?->value],
            'delivery' => ['available' => $this->deliveryAvailable, 'reason_code' => $this->deliveryReason?->value],
            'reason' => $this->reasonCode === null ? null : ['code' => $this->reasonCode->value, 'message' => $this->message],
            'next_opening_at' => $this->nextOpeningAt?->toISOString()];
    }
}
