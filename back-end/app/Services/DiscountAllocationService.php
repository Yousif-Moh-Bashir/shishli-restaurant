<?php

namespace App\Services;

use InvalidArgumentException;

class DiscountAllocationService
{
    /** @param array<string,int> $weights @return array<string,int> */
    public function allocate(int $discount, array $weights): array
    {
        if ($discount < 0 || min($weights ?: [0]) < 0) {
            throw new InvalidArgumentException('Amounts must be non-negative.');
        }
        $total = array_sum($weights);
        if ($discount > $total) {
            throw new InvalidArgumentException('Discount exceeds eligible amount.');
        }
        $allocated = array_fill_keys(array_keys($weights), 0);
        if ($total === 0 || $discount === 0) {
            return $allocated;
        }
        $remainders = [];
        foreach ($weights as $key => $weight) {
            [$allocated[$key],$remainders[$key]] = $this->multiplyDivide($discount, $weight, $total);
        }
        $keys = array_keys($weights);
        usort($keys, fn (string $a, string $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp($a, $b));
        $remaining = $discount - array_sum($allocated);
        foreach ($keys as $key) {
            if ($remaining === 0) {
                break;
            }
            if ($allocated[$key] < $weights[$key]) {
                $allocated[$key]++;
                $remaining--;
            }
        }

        return $allocated;
    }

    /** Exact multiplication/division without overflowing 64-bit integers. @return array{int,int} */
    private function multiplyDivide(int $a, int $b, int $divisor): array
    {
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($b)) as $bit) {
            $quotient *= 2;
            $remainder = $remainder * 2 + ($bit === '1' ? $a : 0);
            $quotient += intdiv($remainder, $divisor);
            $remainder %= $divisor;
        }

        return [$quotient, $remainder];
    }
}
