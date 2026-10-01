<?php
namespace App\Actions\Payments;
use App\Data\Payments\CreatePaymentData;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\GuestOrderAccess;
use App\Services\Money;
use App\Services\Payments\PaymentPresentation;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PaymentStatusWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
class InitiatePaymentAction
{
    public function __construct(private PaymentProviderManager $providers, private GuestOrderAccess $guestAccess,
        private PaymentStatusWriter $writer, private PaymentPresentation $presentation) {}
    public function handle(Order $order, ?User $actor, ?string $key = null, ?string $guestToken = null): Payment
    {
        if ($actor === null) { $this->guestAccess->authorize($order, $guestToken); }
        else { abort_unless($order->user_id === $actor->id, 404); }
        if (DB::transactionLevel() > 0) { throw new PaymentException('PAYMENT_INITIATION_REQUIRES_COMMITTED_ORDER', 409); }
        $identity = $actor !== null ? 'user:'.$actor->uuid : 'guest:'.hash('sha256', (string) $guestToken);
        $key = hash('sha256', 'initiate|'.$identity.'|'.($key ?? (string) Str::uuid()));
        [$payment, $created] = DB::transaction(function () use ($order, $key): array {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->payments()->where('idempotency_key', $key)->first();
            if ($existing !== null) { return [$existing, false]; }
            if ($locked->status === OrderStatus::Cancelled) { throw new PaymentException('ORDER_CANCELLED'); }
            if ($locked->payment_status === PaymentStatus::Paid || $locked->payments()->whereIn('status', [
                PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded,
            ])->exists()) { throw new PaymentException('ORDER_ALREADY_PAID'); }
            if ($locked->payment_method === PaymentMethod::Cash) { throw new PaymentException('PAYMENT_METHOD_NOT_SUPPORTED'); }
            $providerName = config('payments.default_provider');
            $provider = $this->providers->resolve($providerName);
            if (! $provider->supports($locked->payment_method)) { throw new PaymentException('PAYMENT_METHOD_NOT_SUPPORTED'); }
            if ($locked->payments()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])->exists()) {
                throw new PaymentException('PAYMENT_ALREADY_IN_PROGRESS', 409);
            }
            $payment = $locked->payments()->create([
                'method' => $locked->payment_method, 'provider' => $providerName, 'status' => PaymentStatus::Pending,
                'amount' => $locked->total, 'currency' => $locked->currency, 'idempotency_key' => $key,
            ]);
            $payment->transactions()->create(['type' => PaymentTransactionType::Sale, 'status' => PaymentTransactionStatus::Pending,
                'amount' => $payment->amount, 'operation_key' => 'sale', 'request_reference' => $payment->uuid]);
            return [$payment, true];
        }, 3);
        if (! $created) { return $payment; }
        try {
            $result = $this->providers->resolve($payment->provider)->createPayment(new CreatePaymentData(
                $payment->uuid, $order->uuid, $payment->method, $payment->amount, $payment->currency, $key));
        } catch (Throwable) {
            // A timeout may occur after the provider charged the customer. Retain the attempt for reconciliation.
            DB::transaction(function () use ($order, $payment): void {
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PaymentStatus::Pending) {
                    $locked->failure_code = 'PROVIDER_REQUEST_UNCERTAIN';
                    $this->writer->apply($locked, $lockedOrder, PaymentStatus::Processing);
                }
            }, 3);
            throw new PaymentException('PROVIDER_REQUEST_UNCERTAIN', 503);
        }
        try {
            return DB::transaction(function () use ($order, $payment, $result): Payment {
                $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (Money::minor($result->amount) !== Money::minor($locked->amount) || $result->currency !== $locked->currency) {
                    throw new PaymentException('PAYMENT_AMOUNT_MISMATCH');
                }
                if ($result->providerPaymentId === '' || strlen($result->providerPaymentId) > 255
                    || strlen($result->providerReference ?? '') > 255 || strlen($result->providerTransactionId ?? '') > 255) {
                    throw new PaymentException('INVALID_PROVIDER_RESPONSE');
                }
                if ($locked->provider_payment_id !== null && $locked->provider_payment_id !== $result->providerPaymentId) {
                    throw new PaymentException('PROVIDER_REFERENCE_COLLISION');
                }
                $locked->provider_payment_id = $result->providerPaymentId;
                $locked->provider_reference = $result->providerReference;
                $locked->checkout_url = $this->presentation->checkoutUrl($result->checkoutUrl);
                $locked->save();
                // An already processed webhook must not be downgraded by an older initiation response.
                if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled], true)) {
                    $this->writer->apply($locked, $lockedOrder, $result->status, $result->providerTransactionId, $locked->uuid);
                }
                return $locked;
            }, 3);
        } catch (QueryException) {
            throw new PaymentException('PROVIDER_REFERENCE_COLLISION', 409);
        }
    }
}
