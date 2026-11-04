<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

final class Money
{
    public const RULE = 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/';

    public static function cents(mixed $amount): int
    {
        if (! is_scalar($amount) || ! preg_match('/\A\d{1,14}(?:\.\d{1,2})?\z/', (string) $amount)) {
            throw ValidationException::withMessages(['amount' => 'أدخل مبلغًا صحيحًا بمنزلتين عشريتين كحد أقصى.']);
        }
        $parts = explode('.', (string) $amount);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
