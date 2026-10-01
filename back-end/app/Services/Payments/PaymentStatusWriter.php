<?php
namespace App\Services\Payments;
use App\Actions\Payments\SyncOrderPaymentStatusAction;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
/** Internal writer: callers lock the order first, then its payment, in one transaction. */
class PaymentStatusWriter
{
    public function __construct(private PaymentStateMachine $machine, private SyncOrderPaymentStatusAction $sync) {}
    public function apply(Payment $payment, Order $order, PaymentStatus $target,
        ?string $transactionId = null, ?string $requestReference = null, ?int $actorId = null): void
    {
        if ($payment->status === $target && in_array($target, [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled], true)) {
            return;
        }
        $from = $payment->status;
        if ($from !== $target) {
            $this->machine->transition($from, $target);
        }
        if ($target === PaymentStatus::Paid && ($order->payment_status === PaymentStatus::Paid
            || $order->payments()->whereKeyNot($payment->id)->whereIn('status', [
                PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded,
            ])->exists())) {
            throw new PaymentException('ORDER_ALREADY_PAID');
        }
        $payment->status = $target;
        if ($target === PaymentStatus::Paid) { $payment->paid_at = now(); }
        if ($target === PaymentStatus::Failed) {
            $payment->failed_at = now();
            $payment->failure_code = 'PAYMENT_FAILED';
            $payment->failure_message = 'Payment was declined by the provider.';
        }
        if ($target === PaymentStatus::Cancelled) { $payment->cancelled_at = now(); }
        $payment->save();
        $transaction = $payment->transactions()->firstOrCreate(['operation_key' => 'sale'], [
            'type' => PaymentTransactionType::Sale, 'status' => PaymentTransactionStatus::Pending, 'amount' => $payment->amount,
        ]);
        $transaction->status = match ($target) {
            PaymentStatus::Paid => PaymentTransactionStatus::Succeeded,
            PaymentStatus::Failed, PaymentStatus::Cancelled => PaymentTransactionStatus::Failed,
            default => PaymentTransactionStatus::Pending,
        };
        $transaction->provider_transaction_id = $transactionId ?? $transaction->provider_transaction_id;
        $transaction->provider_reference = $payment->provider_reference;
        $transaction->request_reference = $requestReference ?? $transaction->request_reference;
        $transaction->failure_code = $payment->failure_code;
        $transaction->failure_message = $payment->failure_message;
        if ($actorId !== null) { $transaction->metadata = ['collected_by' => $actorId]; }
        if ($transaction->status !== PaymentTransactionStatus::Pending) { $transaction->processed_at = now(); }
        $transaction->save();
        $synced = $this->sync->handle($order);
        if ($from !== $target && $target === PaymentStatus::Paid) { PaymentSucceeded::dispatch($payment, $synced); }
        if ($from !== $target && $target === PaymentStatus::Failed) { PaymentFailed::dispatch($payment); }
    }
}
