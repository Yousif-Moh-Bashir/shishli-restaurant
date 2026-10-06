<?php

namespace App\Services\Customers;

use Carbon\Carbon;

final readonly class CustomerStatisticsData
{
    public function __construct(
        public int $ordersCount, public int $completedOrdersCount, public int $cancelledOrdersCount,
        public string $totalSpent, public ?string $firstOrderAt, public ?string $lastOrderAt,
    ) {}

    public static function fromRow(object $row): self
    {
        $minor = (int) ($row->total_spent_minor ?? 0);

        return new self((int) ($row->orders_count ?? 0), (int) ($row->completed_orders_count ?? 0),
            (int) ($row->cancelled_orders_count ?? 0),
            intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT),
            isset($row->first_order_at) ? Carbon::parse($row->first_order_at)->toISOString() : null,
            isset($row->last_order_at) ? Carbon::parse($row->last_order_at)->toISOString() : null);
    }

    public function toArray(): array
    {
        return ['orders_count' => $this->ordersCount, 'completed_orders_count' => $this->completedOrdersCount,
            'cancelled_orders_count' => $this->cancelledOrdersCount, 'total_spent' => $this->totalSpent,
            'first_order_at' => $this->firstOrderAt, 'last_order_at' => $this->lastOrderAt];
    }
}
