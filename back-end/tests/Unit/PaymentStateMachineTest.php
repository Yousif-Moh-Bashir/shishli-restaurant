<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
use App\Services\Payments\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    #[DataProvider('transitions')]
    public function test_only_explicit_payment_transitions_are_allowed(string $from, string $to, bool $allowed): void
    {
        $machine = new PaymentStateMachine;
        $this->assertSame($allowed, $machine->canTransition(PaymentStatus::from($from), PaymentStatus::from($to)));
        if (! $allowed) {
            $this->expectException(PaymentException::class);
        }
        $this->assertSame(PaymentStatus::from($to), $machine->transition(PaymentStatus::from($from), PaymentStatus::from($to)));
    }

    public static function transitions(): array
    {
        $edges = ['pending' => ['processing', 'paid', 'failed', 'cancelled'],
            'processing' => ['paid', 'failed', 'cancelled'], 'paid' => [], 'failed' => [],
            'cancelled' => [], 'refunded' => [], 'partially_refunded' => []];
        $cases = [];
        foreach ($edges as $from => $targets) {
            foreach (array_keys($edges) as $to) {
                $cases[$from.' -> '.$to] = [$from, $to, in_array($to, $targets, true)];
            }
        }

        return $cases;
    }
}
