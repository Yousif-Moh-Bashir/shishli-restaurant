<?php

namespace App\Services;

use App\Enums\DeliveryIssueCode;

final readonly class DeliveryQuote
{
    public function __construct(
        public bool $isAvailable,
        public string $subtotal,
        public string $deliveryFee,
        public string $estimatedTotal,
        public ?string $zoneUuid = null,
        public ?string $zoneName = null,
        public string $minimumOrder = '0.00',
        public bool $minimumOrderMet = false,
        public string $remainingAmount = '0.00',
        public bool $freeDeliveryApplied = false,
        public ?int $estimatedMinMinutes = null,
        public ?int $estimatedMaxMinutes = null,
        public ?float $distanceKm = null,
        public ?DeliveryIssueCode $issueCode = null,
    ) {}
}
