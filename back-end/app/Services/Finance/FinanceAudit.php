<?php

namespace App\Services\Finance;

use App\Models\Finance\AuditLog;
use App\Models\Finance\RevenueDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class FinanceAudit
{
    public function snapshot(Model $model): array
    {
        $values = Arr::except($model->attributesToArray(), ['id', 'password', 'remember_token', 'attachment_path']);
        if ($model instanceof RevenueDay) {
            $values['lines'] = $model->lines()->with('source')->get()->map(fn ($line): array => ['source_uuid' => $line->source->uuid, 'amount' => $line->amount])->all();
        }

        return $values;
    }

    public function record(User $actor, string $action, Model $model, array $old = [], ?string $reason = null, ?array $new = null): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'branch_id' => $model->branch_id,
            'action' => $action, 'subject_type' => $model->getTable(), 'subject_uuid' => $model->uuid,
            'old_values' => $old, 'new_values' => $new ?? $this->snapshot($model), 'reason' => $reason,
        ]);
    }
}
