<?php

namespace Database\Factories;

use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockReservationFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_inventory_id' => BranchInventory::factory()->state(['reserved' => '1.000']),
            'order_item_id' => function (array $data): int {
                $inventory = BranchInventory::findOrFail($data['branch_inventory_id']);

                return OrderItem::factory()->for(Order::factory()->for($inventory->branch))->create([
                    'product_id' => $inventory->product_id, 'product_uuid' => $inventory->product->uuid,
                    'quantity' => 1, 'line_total' => '19.99'])->id;
            },
            'order_id' => fn (array $data): int => OrderItem::findOrFail($data['order_item_id'])->order_id,
            'quantity' => '1.000', 'status' => 'active', 'reserved_at' => now()];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active', 'consumed_at' => null, 'released_at' => null]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => ['branch_inventory_id' => BranchInventory::factory()->state(['on_hand' => '19.000', 'reserved' => '0.000']), 'status' => 'consumed', 'consumed_at' => now(), 'released_at' => null]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => ['branch_inventory_id' => BranchInventory::factory()->state(['reserved' => '0.000']), 'status' => 'released', 'released_at' => now(), 'consumed_at' => null]);
    }
}
