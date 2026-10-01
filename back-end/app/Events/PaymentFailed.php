<?php
namespace App\Events;
use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class PaymentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;
    public function __construct(public Payment $payment, public string $failureCode = 'PAYMENT_FAILED') {}
}
