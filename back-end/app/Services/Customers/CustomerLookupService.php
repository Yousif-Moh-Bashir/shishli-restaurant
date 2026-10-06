<?php

namespace App\Services\Customers;

use App\Models\User;
use App\Services\Notifications\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CustomerLookupService
{
    public function __construct(private CustomerStatisticsService $statistics, private PhoneNormalizer $phones) {}

    public function customers(): Builder
    {
        return User::query()->whereHas('roles', fn (Builder $query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('name', ['super_admin', 'manager', 'cashier', 'kitchen']));
    }

    public function find(string $uuid): User
    {
        return $this->customers()->where('uuid', $uuid)->firstOrFail();
    }

    public function search(array $data): Builder
    {
        $aggregates = $this->statistics->aggregates()->addSelect('user_id')->whereNotNull('user_id')->groupBy('user_id');
        $query = $this->customers()->leftJoinSub($aggregates, 'customer_totals', 'customer_totals.user_id', '=', 'users.id')
            ->select('users.*')->selectRaw('COALESCE(customer_totals.orders_count, 0) AS orders_count,
                COALESCE(customer_totals.completed_orders_count, 0) AS completed_orders_count,
                COALESCE(customer_totals.cancelled_orders_count, 0) AS cancelled_orders_count,
                COALESCE(customer_totals.total_spent_minor, 0) AS total_spent_minor,
                customer_totals.first_order_at, customer_totals.last_order_at');
        if (isset($data['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $phone = $this->phones->normalize($data['search'], config('outbound_notifications.phone_country'));
            $query->where(function (Builder $query) use ($term, $phone): void {
                $query->whereRaw("users.name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("users.phone LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("users.email LIKE ? ESCAPE '!'", [$term]);
                if ($phone !== null) {
                    $query->orWhere('users.phone_normalized', $phone);
                }
            });
        }
        if (isset($data['phone'])) {
            $phone = $this->phones->normalize($data['phone'], config('outbound_notifications.phone_country'));
            $query->where(fn (Builder $query) => $query->where('users.phone', $data['phone'])
                ->when($phone !== null, fn (Builder $query) => $query->orWhere('users.phone_normalized', $phone)));
        }
        if (isset($data['email'])) {
            $query->where('users.email', $data['email']);
        }
        if (isset($data['is_active'])) {
            $query->where('users.is_active', (bool) $data['is_active']);
        }
        if (isset($data['has_orders'])) {
            (bool) $data['has_orders'] ? $query->whereHas('orders') : $query->whereDoesntHave('orders');
        }
        if (isset($data['registered_from'])) {
            $query->where('users.created_at', '>=', Carbon::parse($data['registered_from'])->startOfDay());
        }
        if (isset($data['registered_to'])) {
            $query->where('users.created_at', '<', Carbon::parse($data['registered_to'])->addDay()->startOfDay());
        }
        $columns = ['created_at' => 'users.created_at', 'name' => 'users.name', 'orders_count' => 'orders_count',
            'total_spent' => 'total_spent_minor', 'last_order_at' => 'customer_totals.last_order_at'];
        $sort = $data['sort'] ?? 'created_at';

        return $query->orderBy($columns[$sort], $data['direction'] ?? 'desc')->orderByDesc('users.id');
    }
}
