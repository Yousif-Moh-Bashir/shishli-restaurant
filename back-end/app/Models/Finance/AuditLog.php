<?php

namespace App\Models\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends FinanceModel
{
    public const UPDATED_AT = null;

    protected $table = 'finance_audit_logs';

    protected $fillable = ['user_id', 'branch_id', 'action', 'subject_type', 'subject_uuid', 'old_values', 'new_values', 'reason'];

    protected $hidden = ['id', 'user_id', 'branch_id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('Finance audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Finance audit records are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
