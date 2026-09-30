<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function minor(string $amount): int
    {
        if (! preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            throw ValidationException::withMessages(['price' => 'السعر غير صالح أو يتجاوز الحد المسموح.']);
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0 || $minor > 999999999999) {
            throw ValidationException::withMessages(['price' => 'إجمالي السعر يتجاوز الحد المسموح للسلة.']);
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
