<?php

namespace App\Services;

readonly class DiscountResult
{
    /** @param array<string,int> $affectedItems */
    public function __construct(public int $discountAmount, public int $eligibleSubtotal, public array $affectedItems) {}
}
