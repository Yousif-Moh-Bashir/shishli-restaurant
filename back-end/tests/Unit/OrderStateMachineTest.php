<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Services\OrderStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStateMachineTest extends TestCase
{
    #[DataProvider('matrix')]
    public function test_only_allowed_edges_can_transition(string $type, string $from, string $to, bool $allowed): void
    {
        $machine = new OrderStateMachine;
        $this->assertSame($allowed, $machine->canTransition(OrderStatus::from($from), OrderStatus::from($to), OrderType::from($type)));
        $this->assertSame($allowed, in_array(OrderStatus::from($to), $machine->allowedTransitions(OrderStatus::from($from), OrderType::from($type)), true));
        if ($allowed) {
            $this->assertSame(OrderStatus::from($to), $machine->transition(OrderStatus::from($from), OrderStatus::from($to), OrderType::from($type)));
        }
    }

    public static function matrix(): array
    {
        $delivery = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'], 'ready' => ['out_for_delivery', 'cancelled'],
            'out_for_delivery' => ['completed'], 'completed' => [], 'cancelled' => []];
        $pickup = $delivery;
        $pickup['ready'] = ['completed', 'cancelled'];
        $pickup['out_for_delivery'] = [];
        $cases = [];
        foreach (['delivery' => $delivery, 'pickup' => $pickup] as $type => $edges) {
            foreach ($edges as $from => $targets) {
                foreach (array_keys($edges) as $to) {
                    $cases[$type.' '.$from.' -> '.$to] = [$type, $from, $to, in_array($to, $targets, true)];
                }
            }
        }

        return $cases;
    }
}
