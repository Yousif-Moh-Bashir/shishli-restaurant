<?php

namespace App\Services\Finance;

use App\Models\Branch;
use App\Models\Finance\RevenueDay;
use App\Models\Finance\RevenueSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueService
{
    public function __construct(private FinanceAccess $access, private FinanceAudit $audit) {}

    public function save(User $actor, array $data, ?string $uuid = null, bool $correction = false): RevenueDay
    {
        return DB::transaction(function () use ($actor, $data, $uuid, $correction): RevenueDay {
            $branch = $this->access->branch($actor, $data['branch_uuid']);
            // Serialize creation for a branch; the unique index is an additional database guarantee.
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $day = $uuid ? $this->access->scope(RevenueDay::query(), $actor)->where('uuid', $uuid)->lockForUpdate()->firstOrFail() : new RevenueDay;
            $old = $day->exists ? $this->audit->snapshot($day) : [];
            if ($day->exists) {
                abort_unless($day->version === (int) $data['version'], 409, 'تم تعديل السجل. أعد تحميله.');
                abort_unless($correction ? $day->status === 'approved' : $day->status === 'draft', 409);
                abort_unless($day->branch_id === $branch->id && $day->date->toDateString() === $data['date'], 422, 'لا يمكن تغيير تاريخ أو فرع السجل.');
            } elseif (RevenueDay::where('branch_id', $branch->id)->whereDate('date', $data['date'])->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['date' => 'يوجد سجل لهذا اليوم والفرع؛ افتح السجل الموجود.']);
            }
            $sources = RevenueSource::whereIn('uuid', array_column($data['lines'], 'source_uuid'))->lockForUpdate()->get()->keyBy('uuid');
            $previousSources = $day->exists ? $day->lines()->pluck('source_id')->all() : [];
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $index => $line) {
                $source = $sources->get($line['source_uuid']);
                if (! $source || (! $source->is_active && ! in_array($source->id, $previousSources, true))) {
                    throw ValidationException::withMessages(["lines.$index.source_uuid" => 'مصدر الإيراد غير متاح.']);
                }
                $cents = Money::cents($line['amount']);
                $total += $cents;
                $lines[] = ['source_id' => $source->id, 'amount' => Money::decimal($cents)];
            }
            if ($total === 0) {
                throw ValidationException::withMessages(['lines' => 'أدخل مبلغ إيراد موجبًا في وسيلة واحدة على الأقل.']);
            }
            abort_if($total > 9999999999999999, 422);
            $day->fill($data);
            $day->branch_id = $branch->id;
            $day->total = Money::decimal($total);
            if (! $day->exists) {
                $day->created_by = $actor->id;
                $day->status = 'draft';
                $day->version = 1;
            } else {
                $day->version++;
            }
            if ($correction) {
                $day->approved_by = $actor->id;
                $day->approved_at = now();
            }
            $day->save();
            $day->lines()->delete();
            $day->lines()->createMany($lines);
            $this->audit->record($actor, $correction ? 'revenues.correct' : ($old ? 'revenues.update' : 'revenues.create'), $day, $old, $data['reason'] ?? null);

            return $day;
        }, 3);
    }

    public function approve(User $actor, string $uuid, int $version): RevenueDay
    {
        return DB::transaction(function () use ($actor, $uuid, $version): RevenueDay {
            $day = $this->access->scope(RevenueDay::query(), $actor)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($day->status === 'draft' && $day->version === $version, 409);
            $old = $this->audit->snapshot($day);
            $day->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'version' => $day->version + 1])->save();
            $this->audit->record($actor, 'revenues.approve', $day, $old);

            return $day;
        }, 3);
    }
}
