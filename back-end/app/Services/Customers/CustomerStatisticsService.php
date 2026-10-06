<?php

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Query\Builder;

class CustomerStatisticsService
{
    /** Operational spending is completed Order.total, independently of payment timing. */
    public function aggregates(): Builder
    {
        $query = Order::query()->toBase();
        $integerType = $query->getConnection()->getDriverName() === 'mysql' ? 'SIGNED' : 'BIGINT';

        return $query
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS completed_orders_count', [OrderStatus::Completed->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS cancelled_orders_count', [OrderStatus::Cancelled->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN CAST(ROUND(total * 100, 0) AS {$integerType}) ELSE 0 END), 0) AS total_spent_minor", [OrderStatus::Completed->value])
            ->selectRaw('MIN(placed_at) AS first_order_at, MAX(placed_at) AS last_order_at');
    }

    public function forUser(User $user): CustomerStatisticsData
    {
        return CustomerStatisticsData::fromRow($this->aggregates()->where('user_id', $user->id)->first());
    }

    public function loadProfile(User $user): User
    {
        $user->setAttribute('customer_statistics', $this->forUser($user));
        $user->load('defaultAddress');

        return $user;
    }
}
