<?php

namespace App\Services\Customers;

use App\Models\Order;
use App\Services\Notifications\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestCustomerQuery
{
    public function __construct(private CustomerStatisticsService $statistics, private PhoneNormalizer $phones) {}

    public function phone(string $phone): string
    {
        $normalized = $this->phones->normalize($phone, config('outbound_notifications.phone_country'));
        if ($normalized === null) {
            throw ValidationException::withMessages(['phone' => 'رقم الهاتف غير صالح وفق سياسة التطبيع الحالية.']);
        }

        return $normalized;
    }

    public function summaries(array $data): Builder
    {
        $aggregates = $this->statistics->aggregates()->whereNull('user_id')->whereNotNull('customer_phone_normalized')
            ->addSelect('customer_phone_normalized')->groupBy('customer_phone_normalized');
        if (isset($data['phone'])) {
            $aggregates->where('customer_phone_normalized', $this->phone($data['phone']));
        }
        $query = DB::query()->fromSub($aggregates, 'guests')->select('guests.*')
            ->addSelect(['latest_name' => Order::query()->select('customer_name')->whereNull('user_id')
                ->whereColumn('customer_phone_normalized', 'guests.customer_phone_normalized')
                ->orderByDesc('placed_at')->orderByDesc('id')->limit(1)]);

        return $query->orderByDesc('last_order_at')->orderBy('customer_phone_normalized');
    }

    public function orders(string $phone): EloquentBuilder
    {
        return Order::query()->whereNull('user_id')->where('customer_phone_normalized', $this->phone($phone));
    }
}
