<?php
namespace App\Events;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;
    public function __construct(public Payment $payment, public Order $order) {}
}
