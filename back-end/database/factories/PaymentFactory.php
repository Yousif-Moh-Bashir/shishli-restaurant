<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['method' => PaymentMethod::Cash, 'provider' => null, 'status' => PaymentStatus::Pending,
            'order_id' => fn (array $attributes): int => OrderFactory::new()->create(['payment_method' => $attributes['method'], 'total' => '85.00'])->id,
            'amount' => fn (array $attributes): string => Order::findOrFail($attributes['order_id'])->total,
            'currency' => fn (array $attributes): string => Order::findOrFail($attributes['order_id'])->currency];
    }

    public function pending(): static
    {
        return $this->state(['status' => PaymentStatus::Pending]);
    }

    public function processing(): static
    {
        return $this->state(['status' => PaymentStatus::Processing]);
    }

    public function paid(): static
    {
        return $this->state(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentStatus::Failed, 'failed_at' => now()]);
    }

    public function cash(): static
    {
        return $this->state(['method' => PaymentMethod::Cash, 'provider' => null]);
    }

    public function online(): static
    {
        return $this->state(['method' => PaymentMethod::Card, 'provider' => 'test']);
    }
}
