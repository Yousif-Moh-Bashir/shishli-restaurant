<?php

namespace App\Services;

final readonly class OrderTrackingData
{
    public function __construct(public array $order, public array $progress, public array $timeline,
        public array $estimates, public array $branch, public array $paymentSummary, public array $customerSafeSummary,
        public array $items, public array $summary, public ?array $destination, public ?string $lastUpdatedAt) {}
}
