<?php

namespace App\Services;

final class AddressNormalizer
{
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
