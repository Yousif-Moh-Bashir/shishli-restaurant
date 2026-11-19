<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PrintJob extends Model
{
    use HasFactory;

    public const ORIGINAL_COPY = '00000000-0000-0000-0000-000000000000';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'order_id', 'acceptance_id', 'print_agent_id', 'receipt', 'requested_by'];

    protected function casts(): array
    {
        return ['receipt' => 'encrypted:array', 'available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (PrintJob $job) => $job->uuid ??= (string) Str::uuid());
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PrintJobAttempt::class)->orderBy('number');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
