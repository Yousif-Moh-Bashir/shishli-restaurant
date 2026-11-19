<?php

namespace App\Services\Drivers;

use App\Enums\OrderStatus;
use App\Models\DeliveryProgressAlert;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DeliveryProgressService
{
    private const CLOCKS = ['assigned' => 'driver_accepted_at', 'picked_up' => 'delivery_picked_up_at', 'on_the_way' => 'delivery_on_the_way_at'];

    private function minutes(string $stage, string $level = 'minutes'): int
    {
        return max(0, min(1440, (int) config('delivery_progress.'.$level.'.'.$stage, 0)));
    }

    public function forOrder(Order $order): array
    {
        $stage = $order->delivery_status?->value ?? '';
        $column = self::CLOCKS[$stage] ?? null;
        $last = $column === null ? null : $order->{$column};
        if ($column !== null && $order->driver_accepted_at !== null && $last !== null && $order->driver_accepted_at->greaterThan($last)) {
            $last = $order->driver_accepted_at;
        }
        if ($stage === 'assigned' && $order->ready_at !== null && $last !== null && $order->ready_at->greaterThan($last)) {
            $last = $order->ready_at;
        }
        $minutes = $this->minutes($stage);
        $escalation = $this->minutes($stage, 'escalation_minutes');
        $active = $order->type->value === 'delivery' && $column !== null && $order->driver_id !== null && $order->delivery_task_closed_at === null
            && ! in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
            && ($stage === 'on_the_way' ? $order->status === OrderStatus::OutForDelivery : $order->status === OrderStatus::Ready);
        $due = $active && $last !== null && $minutes > 0 ? $last->copy()->addMinutes($minutes) : null;
        $stalled = $due !== null && $due->lessThanOrEqualTo(now());
        $escalationDue = $due !== null && $escalation > 0 ? $last->copy()->addMinutes(max($minutes, $escalation)) : null;
        $escalated = $stalled && $escalationDue !== null && $escalationDue->lessThanOrEqualTo(now());

        return ['last_progress_at' => $last?->toISOString(), 'due_at' => $due?->toISOString(), 'threshold_minutes' => $minutes,
            'stage' => $stage, 'escalated' => $escalated, 'escalation_due_at' => $escalationDue?->toISOString(),
            'elapsed_minutes' => $last === null ? null : max(0, (int) $last->diffInMinutes(now())),
            'stalled' => $stalled, 'key' => hash('sha256', implode(':', [$order->uuid, $order->driver_id, $stage, $last?->toISOString()])),
            'message' => $stalled ? ($stage === 'on_the_way'
                ? 'لم يُسجّل تقدم للرحلة خلال المدة المحددة. أكّد التسليم عند حدوثه بعد استيفاء الدفع، أو اطلب تدخل الإدارة.'
                : 'تأخر بدء الرحلة. سجّل استلام الطلب وبدء التوصيل عند حدوثهما فعلًا، أو اطلب تدخل الإدارة.') : null];
    }

    public function constrainStalled(Builder $query, bool $escalated = false): void
    {
        $query->where('type', 'delivery')->whereNotNull('driver_id')->whereNull('delivery_task_closed_at')
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->where(function (Builder $stages) use ($escalated): void {
                $enabled = false;
                foreach (self::CLOCKS as $stage => $column) {
                    $minutes = $this->minutes($stage);
                    $escalation = $this->minutes($stage, 'escalation_minutes');
                    if ($minutes === 0 || ($escalated && $escalation === 0)) {
                        continue;
                    }
                    $minutes = $escalated ? max($minutes, $escalation) : $minutes;
                    $enabled = true;
                    $clock = 'CASE WHEN '.$column.' IS NULL THEN NULL WHEN driver_accepted_at IS NULL THEN '.$column
                        .' WHEN driver_accepted_at > '.$column.' THEN driver_accepted_at ELSE '.$column.' END';
                    if ($stage === 'assigned') {
                        $clock = 'CASE WHEN ready_at > ('.$clock.') THEN ready_at ELSE ('.$clock.') END';
                    }
                    $stages->orWhere(function (Builder $stageQuery) use ($stage, $minutes, $clock): void {
                        $stageQuery->where('delivery_status', $stage)->whereRaw('('.$clock.') <= ?', [now()->subMinutes($minutes)]);
                        $stageQuery->where('status', $stage === 'on_the_way' ? OrderStatus::OutForDelivery : OrderStatus::Ready);
                    });
                }
                if (! $enabled) {
                    $stages->whereRaw('1 = 0');
                }
            });
    }

    public function synchronize(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }
            $progress = $this->forOrder($order);
            $active = DeliveryProgressAlert::where('order_id', $orderId)->whereNull('resolved_at');
            (clone $active)->where(function (Builder $query) use ($progress): void {
                $query->where('progress_key', '!=', $progress['key']);
                if (! $progress['stalled']) {
                    $query->orWhere('progress_key', $progress['key']);
                }
            })->update(['resolved_at' => now()]);
            if (! $progress['stalled']) {
                return;
            }
            $alert = DeliveryProgressAlert::firstOrCreate(['progress_key' => $progress['key']], [
                'order_id' => $orderId, 'driver_id' => $order->driver_id, 'stage' => $progress['stage'],
                'last_progress_at' => $progress['last_progress_at'], 'reminded_at' => now(),
            ]);
            if ($alert->resolved_at !== null) {
                $alert->update(['resolved_at' => null]);
            }
            if ($progress['escalated'] && $alert->escalated_at === null) {
                $alert->update(['escalated_at' => now()]);
            }
        }, 3);
    }

    public function scan(): int
    {
        $count = 0;
        Order::where(function (Builder $query): void {
            $query->whereIn('id', DeliveryProgressAlert::select('order_id')->whereNull('resolved_at'))
                ->orWhere(function (Builder $stalled): void {
                    $this->constrainStalled($stalled);
                });
        })->select('id')->chunkById(100, function ($orders) use (&$count): void {
            foreach ($orders as $order) {
                $this->synchronize($order->id);
                $count++;
            }
        });

        return $count;
    }
}
