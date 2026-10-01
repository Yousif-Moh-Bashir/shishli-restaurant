<?php

namespace Database\Factories;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => PaymentFactory::new(), 'type' => PaymentTransactionType::Sale,
            'status' => PaymentTransactionStatus::Pending,
            'provider' => fn (array $attributes): ?string => Payment::findOrFail($attributes['payment_id'])->provider,
            'amount' => fn (array $attributes): string => Payment::findOrFail($attributes['payment_id'])->amount];
    }

    public function pending(): static
    {
        return $this->state(['status' => PaymentTransactionStatus::Pending]);
    }

    public function succeeded(): static
    {
        return $this->state(['status' => PaymentTransactionStatus::Succeeded, 'processed_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentTransactionStatus::Failed, 'processed_at' => now()]);
    }

    public function sale(): static
    {
        return $this->state(['type' => PaymentTransactionType::Sale]);
    }

    public function refund(): static
    {
        return $this->state(['type' => PaymentTransactionType::Refund]);
    }
}
