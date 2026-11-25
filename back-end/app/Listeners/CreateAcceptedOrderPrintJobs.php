<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Printing\AcceptedOrderPrinting;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateAcceptedOrderPrintJobs
{
    public function __construct(private AcceptedOrderPrinting $printing) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! config('printing.enabled') || $event->fromStatus !== OrderStatus::Pending || $event->toStatus !== OrderStatus::Confirmed || $event->actorId === null) {
            return;
        }
        try {
            $history = $event->order->statusHistory()->staffAcceptance()
                ->where('changed_by', $event->actorId)
                ->where('source', $event->source)
                ->first();
            if ($history !== null) {
                $this->printing->capture($history->id);
            }
        } catch (Throwable) {
            try {
                Log::warning('printing.capture_deferred', ['order_uuid' => $event->order->uuid]);
            } catch (Throwable) {
            }
        }
    }
}
