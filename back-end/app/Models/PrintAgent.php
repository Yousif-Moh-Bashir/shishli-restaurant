<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrintAgent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'branch_id', 'token_hash', 'activation_history_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (PrintAgent $agent) => $agent->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
