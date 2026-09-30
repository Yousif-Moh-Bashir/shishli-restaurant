<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cart> */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => null, 'branch_id' => Branch::factory(), 'status' => CartStatus::Active,
            'subtotal' => '0.00', 'total' => '0.00', 'expires_at' => now()->addDays(7)];
    }

    public function guest(): static
    {
        return $this->state(['user_id' => null]);
    }

    public function authenticated(User $user): static
    {
        return $this->state(['user_id' => $user->id, 'expires_at' => null]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->for($branch);
    }

    public function converted(): static
    {
        return $this->state(['status' => CartStatus::Converted]);
    }

    public function abandoned(): static
    {
        return $this->state(['status' => CartStatus::Abandoned]);
    }
}
