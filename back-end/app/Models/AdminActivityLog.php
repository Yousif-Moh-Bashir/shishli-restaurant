<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminActivityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_uuid', 'description', 'old_values', 'new_values', 'ip_hash'];

    protected $hidden = ['id', 'user_id', 'ip_hash'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new \LogicException('Activity records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Activity records are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
