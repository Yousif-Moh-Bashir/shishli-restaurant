<?php
namespace App\Services\Payments;
use App\Contracts\Payments\PaymentProviderInterface;
use App\Exceptions\PaymentException;
use Illuminate\Contracts\Container\Container;
class PaymentProviderManager
{
    public function __construct(private Container $container) {}
    public function resolve(?string $name = null): PaymentProviderInterface
    {
        $name ??= config('payments.default_provider');
        $driver = is_string($name) && preg_match('/^[a-z0-9_-]{1,50}$/D', $name)
            ? config('payments.providers.'.$name.'.driver') : null;
        if (! is_string($driver) || ! is_subclass_of($driver, PaymentProviderInterface::class)) {
            throw new PaymentException('PAYMENT_PROVIDER_NOT_CONFIGURED', 503);
        }
        return $this->container->make($driver);
    }
    public function webhookProvider(string $name): PaymentProviderInterface
    {
        abort_unless(array_key_exists($name, config('payments.providers', [])), 404);
        return $this->resolve($name);
    }
}
