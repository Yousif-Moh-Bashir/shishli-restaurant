<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Services\OrderNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'order_number' => app(OrderNumberGenerator::class)->generate(),
            'type' => OrderType::Pickup, 'status' => OrderStatus::Pending, 'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Pending, 'customer_name' => fake()->name(), 'customer_phone' => '0501234567',
            'customer_email' => fake()->safeEmail(), 'subtotal' => '0.00', 'delivery_fee' => '0.00',
            'discount_total' => '0.00', 'tax_total' => '0.00', 'total' => '0.00', 'currency' => 'SAR', 'placed_at' => now()];
    }

    public function pickup(): static
    {
        return $this->state(['type' => OrderType::Pickup, 'delivery_fee' => '0.00']);
    }

    public function delivery(): static
    {
        return $this->state(['type' => OrderType::Delivery])->has(OrderAddressFactory::new(), 'address');
    }

    public function confirmed(): static
    {
        return $this->state(['status' => OrderStatus::Confirmed]);
    }

    public function completed(): static
    {
        return $this->state(['status' => OrderStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => OrderStatus::Cancelled]);
    }
}
