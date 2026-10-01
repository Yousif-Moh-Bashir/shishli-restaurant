<?php
namespace App\Services\Payments;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
class PaymentStateMachine
{
    /** @return list<PaymentStatus> */
    public function allowedTransitions(PaymentStatus $from): array
    {
        return match ($from) {
            PaymentStatus::Pending => [PaymentStatus::Processing, PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled],
            PaymentStatus::Processing => [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled],
            default => [],
        };
    }
    public function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        return in_array($to, $this->allowedTransitions($from), true);
    }
    public function transition(PaymentStatus $from, PaymentStatus $to): PaymentStatus
    {
        if (! $this->canTransition($from, $to)) {
            throw new PaymentException('PAYMENT_TRANSITION_NOT_ALLOWED');
        }
        return $to;
    }
}
