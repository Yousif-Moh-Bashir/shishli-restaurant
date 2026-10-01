<?php

namespace App\Services;

use Illuminate\Support\Str;

class OrderNumberGenerator
{
    public function generate(): string
    {
        return 'SH-'.now()->format('ymd').'-'.strtoupper((string) Str::ulid());
    }
}
