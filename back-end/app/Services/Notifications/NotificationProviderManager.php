<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailProviderInterface;
use App\Contracts\Notifications\SmsProviderInterface;
use App\Contracts\Notifications\WhatsAppProviderInterface;
use App\Enums\NotificationChannel;
use App\Enums\NotificationErrorCode;
use App\Exceptions\NotificationDeliveryException;
use Illuminate\Contracts\Container\Container;

class NotificationProviderManager
{
    public function __construct(private Container $container) {}

    public function providerName(NotificationChannel $channel): ?string
    {
        $name = config('outbound_notifications.channels.'.$channel->value.'.provider');

        return is_string($name) && preg_match('/^[a-z0-9_-]{1,50}$/D', $name) ? $name : null;
    }

    public function resolve(NotificationChannel $channel, ?string $name = null): SmsProviderInterface|WhatsAppProviderInterface|EmailProviderInterface
    {
        $name ??= $this->providerName($channel);
        $interface = match ($channel) {
            NotificationChannel::Sms => SmsProviderInterface::class,NotificationChannel::WhatsApp => WhatsAppProviderInterface::class,NotificationChannel::Email => EmailProviderInterface::class
        };
        $driver = $name !== null && preg_match('/^[a-z0-9_-]{1,50}$/D', $name) ? config('outbound_notifications.providers.'.$channel->value.'.'.$name) : null;
        if (! is_string($driver) || ! is_subclass_of($driver, $interface) || (! app()->environment('testing') && str_starts_with($driver, 'Tests\\'))) {
            throw new NotificationDeliveryException(NotificationErrorCode::ProviderNotConfigured);
        }

        return $this->container->make($driver);
    }
}
