<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SettingChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['setting_key', 'old_value', 'new_value', 'changed_by'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new \LogicException('Setting audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Setting audit records are immutable.'));
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
