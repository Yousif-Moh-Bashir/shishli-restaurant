<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order, public OrderStatus $fromStatus, public OrderStatus $toStatus,
        public ?int $actorId, public OrderStatusSource $source) {}
}
