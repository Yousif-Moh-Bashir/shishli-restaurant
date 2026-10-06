<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

final class ReportMath
{
    public static function cents(string $column): string
    {
        $type = DB::connection()->getDriverName() === 'mysql' ? 'SIGNED' : 'BIGINT';

        return "CAST(ROUND({$column} * 100, 0) AS {$type})";
    }

    /** Reporting totals can exceed the checkout's per-cart monetary limit. */
    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function ratio(int $numerator, int $denominator): int
    {
        return $denominator === 0 ? 0 : ($numerator < 0 ? -1 : 1) * intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);
    }

    public static function row(object|array $row): array
    {
        $result = [];
        foreach ((array) $row as $key => $value) {
            if (str_ends_with($key, '_minor')) {
                $result[substr($key, 0, -6)] = self::decimal((int) $value);
            } elseif (str_ends_with($key, '_count') || in_array($key, ['quantity_sold', 'completed_orders', 'cancelled_orders', 'delivery_orders', 'pickup_orders'])) {
                $result[$key] = (int) $value;
            } else {
                $result[$key] = $value;
            }
        }
        if (array_key_exists('completed_sales_minor', (array) $row)) {
            $count = $result['completed_orders_count'] ?? $result['completed_orders'] ?? 0;
            $result['average_order_value'] = self::decimal(self::ratio((int) ((array) $row)['completed_sales_minor'], $count));
        }

        return $result;
    }

    public static function comparison(int $current, int $previous): array
    {
        return ['percentage_change' => $previous === 0 ? ($current === 0 ? '0.00' : null) : self::decimal(self::ratio(($current - $previous) * 10000, $previous)),
            'trend' => $current === $previous ? 'flat' : ($current > $previous ? 'up' : 'down')];
    }
}
