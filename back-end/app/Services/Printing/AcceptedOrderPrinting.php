<?php

namespace App\Services\Printing;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;

class AcceptedOrderPrinting
{
    public function __construct(private ReceiptSnapshot $snapshot) {}

    public function capture(int $historyId): void
    {
        if (! config('printing.enabled')) {
            return;
        }
        $history = OrderStatusHistory::staffAcceptance()->find($historyId);
        if ($history === null) {
            return;
        }
        DB::transaction(function () use ($history): void {
            $order = Order::whereKey($history->order_id)->lockForUpdate()->firstOrFail();
            $agent = PrintAgent::where('branch_id', $order->branch_id)->lockForUpdate()->first();
            if ($agent === null || ! $agent->enabled || $history->id <= $agent->activation_history_id
                || $history->created_at->lt($agent->activated_at) || $order->status === OrderStatus::Cancelled) {
                return;
            }
            foreach (['kitchen', 'cashier'] as $destination) {
                PrintJob::firstOrCreate(['acceptance_id' => $history->id, 'destination' => $destination, 'copy_key' => PrintJob::ORIGINAL_COPY],
                    ['order_id' => $order->id, 'print_agent_id' => $agent->id,
                        'status' => $history->created_at->lt(now()->subMinutes(config('printing.max_age_minutes'))) ? 'review_required' : 'pending',
                        'receipt' => $this->snapshot->make($order, $history, $destination), 'available_at' => now()]);
            }
        }, 3);
    }

    public function recover(): int
    {
        if (! config('printing.enabled')) {
            return 0;
        }
        app(PrintJobBroker::class)->expireLeases();
        $count = 0;
        foreach (PrintAgent::where('enabled', true)->get() as $agent) {
            OrderStatusHistory::staffAcceptance()
                ->where('id', '>', $agent->activation_history_id)->where('created_at', '>=', $agent->activated_at)
                ->whereHas('order', fn ($query) => $query->where('branch_id', $agent->branch_id)->where('status', '!=', 'cancelled'))
                ->whereRaw('(select count(*) from print_jobs where print_jobs.acceptance_id = order_status_histories.id and print_jobs.copy_key = ?) < 2', [PrintJob::ORIGINAL_COPY])
                ->orderBy('id')->limit(200)->get()->each(function ($history) use (&$count): void {
                    $this->capture($history->id);
                    $count++;
                });
        }

        return $count;
    }
}
