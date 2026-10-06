<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Quantities are integer thousandths internally, independent of monetary arithmetic. DECIMAL(12,3). */
final class StockQuantity
{
    public const MAX = 999999999999;

    public static function minor(string|int|float $value, bool $signed = false): int
    {
        $text = (string) $value;
        $pattern = $signed ? '/^([-+]?)([0-9]{1,9})(?:\\.([0-9]{1,3}))?$/D' : '/^()([0-9]{1,9})(?:\\.([0-9]{1,3}))?$/D';
        if (! preg_match($pattern, $text, $parts)) {
            throw ValidationException::withMessages(['quantity' => 'الكمية غير صالحة؛ الحد الأقصى ثلاث منازل عشرية.']);
        }
        $minor = (int) $parts[2] * 1000 + (int) str_pad($parts[3] ?? '', 3, '0');

        return ($parts[1] ?? '') === '-' ? -$minor : $minor;
    }

    public static function decimal(int $minor): string
    {
        if (abs($minor) > self::MAX) {
            throw ValidationException::withMessages(['quantity' => 'الكمية تتجاوز الحد المسموح.']);
        }

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 1000).'.'.str_pad((string) (abs($minor) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
