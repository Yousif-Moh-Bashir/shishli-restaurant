<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdminOrderQuery
{
    public function query(AdminOrderFilters $filters): Builder
    {
        $data = $filters->values;
        $query = Order::query()->without('promotions');
        foreach (['status', 'type', 'payment_status', 'payment_method', 'order_number'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        foreach (['branch' => 'branch', 'customer_uuid' => 'user'] as $field => $relation) {
            if (isset($data[$field])) {
                $query->whereHas($relation, fn (Builder $q) => $q->where('uuid', $data[$field]));
            }
        }
        if (isset($data['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(function (Builder $q) use ($term): void {
                foreach (['order_number', 'customer_name', 'customer_phone'] as $column) {
                    $q->orWhereRaw($column." LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        $timezone = app(RestaurantSettingsService::class)->getString('restaurant.timezone');
        if (isset($data['date_from'])) {
            $query->where('placed_at', '>=', CarbonImmutable::parse($data['date_from'], $timezone)->startOfDay()->utc());
        }
        if (isset($data['date_to'])) {
            $query->where('placed_at', '<', CarbonImmutable::parse($data['date_to'], $timezone)->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    public function counts(AdminOrderFilters $filters): array
    {
        $query = $this->query($filters);
        $counts = [];
        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ((clone $query)->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->aggregate;
        }
        $start = CarbonImmutable::now(app(RestaurantSettingsService::class)->getString('restaurant.timezone'))->startOfDay();
        foreach (['completed', 'cancelled'] as $state) {
            $counts[$state.'_today'] = (clone $query)->where($state.'_at', '>=', $start->utc())->where($state.'_at', '<', $start->addDay()->utc())->count();
        }

        return $counts;
    }
}
