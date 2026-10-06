<?php

namespace Tests\Unit;

use App\Services\DiscountAllocationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiscountAllocationTest extends TestCase
{
    #[DataProvider('allocations')]
    public function test_exact_allocation(int $amount, array $weights, array $expected): void
    {
        $result = (new DiscountAllocationService)->allocate($amount, $weights);
        $this->assertSame($expected, $result);
        $this->assertSame($amount, array_sum($result));
        foreach ($result as $key => $value) {
            $this->assertLessThanOrEqual($weights[$key], $value);
        }
    }

    public static function allocations(): array
    {
        return [
            [1000, ['a' => 6000, 'b' => 4000], ['a' => 600, 'b' => 400]],
            [1, ['c' => 1, 'b' => 1, 'a' => 1], ['c' => 0, 'b' => 0, 'a' => 1]],
            [2, ['a' => 1, 'b' => 1, 'c' => 1], ['a' => 1, 'b' => 1, 'c' => 0]],
            [3, ['a' => 0, 'b' => 7, 'c' => 3], ['a' => 0, 'b' => 2, 'c' => 1]],
            [0, ['a' => 0], ['a' => 0]],
            [999999999999, ['a' => 999999999999, 'b' => 1], ['a' => 999999999998, 'b' => 1]],
            [8, ['a' => 9, 'b' => 21], ['a' => 2, 'b' => 6]],
        ];
    }

    public function test_overallocation_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DiscountAllocationService)->allocate(2, ['a' => 1]);
    }

    public function test_negative_weights_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DiscountAllocationService)->allocate(1, ['a' => -1]);
    }
}
