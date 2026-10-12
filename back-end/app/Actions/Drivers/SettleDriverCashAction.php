<?php

namespace App\Actions\Drivers;

use App\Models\DriverCashCollection;
use App\Models\DriverCashSettlement;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettleDriverCashAction
{
    public function __construct(private AdminActivityService $audit) {}

    public function handle(User $actor, User $driver, array $uuids, string $amount, string $reason): DriverCashSettlement
    {
        abort_unless($actor->can('drivers.settle_cash'), 403);

        return DB::transaction(function () use ($actor, $driver, $uuids, $amount, $reason): DriverCashSettlement {
            User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $collections = DriverCashCollection::where('driver_id', $driver->id)->whereIn('uuid', $uuids)->whereNull('settlement_id')->orderBy('id')->lockForUpdate()->get();
            abort_if($collections->count() !== count($uuids), 409);
            $currencies = $collections->pluck('currency')->unique();
            $minor = $collections->sum(fn (DriverCashCollection $collection): int => Money::minor($collection->amount));
            if ($currencies->count() !== 1 || $minor !== Money::minor($amount)) {
                throw ValidationException::withMessages(['amount' => 'المبلغ المستلم يجب أن يساوي مجموع التحصيلات المختارة.']);
            }
            $settlement = DriverCashSettlement::create(['driver_id' => $driver->id, 'cashier_id' => $actor->id,
                'amount' => $amount, 'currency' => $currencies->first(), 'reason' => $reason]);
            foreach ($collections as $collection) {
                $collection->settlement_id = $settlement->id;
                $collection->settled_at = now();
                $collection->save();
            }
            $this->audit->record($actor, 'drivers.settle_cash', 'driver_cash_settlement', $settlement->uuid,
                [], ['driver_uuid' => $driver->uuid, 'amount' => $amount], $reason);

            return $settlement;
        }, 3);
    }
}
