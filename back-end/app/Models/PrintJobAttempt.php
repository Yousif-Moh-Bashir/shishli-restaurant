<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrintJobAttempt extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'print_job_id'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (PrintJobAttempt $attempt) => $attempt->uuid ??= (string) Str::uuid());
    }
}
