<?php

namespace App\Services\Customers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CustomerOrderQuery
{
    public function apply(Builder $query, array $data): Builder
    {
        foreach (['status', 'type', 'payment_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['date_from'])) {
            $query->where('placed_at', '>=', Carbon::parse($data['date_from'])->startOfDay());
        }
        if (isset($data['date_to'])) {
            $query->where('placed_at', '<', Carbon::parse($data['date_to'])->addDay()->startOfDay());
        }

        return $query->with('branch')->orderByDesc('placed_at')->orderByDesc('id');
    }
}
