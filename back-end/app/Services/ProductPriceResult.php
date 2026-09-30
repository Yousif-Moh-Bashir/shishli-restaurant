<?php

namespace App\Services;

final readonly class ProductPriceResult
{
    public function __construct(
        public string $basePrice,
        public string $optionsTotal,
        public string $unitPrice,
        public string $lineTotal,
    ) {}

    public function attributes(): array
    {
        return ['base_price' => $this->basePrice, 'options_total' => $this->optionsTotal,
            'unit_price' => $this->unitPrice, 'line_total' => $this->lineTotal];
    }
}
