<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;

class NotificationChannelResolver
{
    /** @return list<NotificationChannel> */
    public function resolve(NotificationType $type): array
    {
        return array_values(array_filter(NotificationChannel::cases(), fn (NotificationChannel $channel): bool => config('outbound_notifications.channels.'.$channel->value.'.enabled', false) === true
            && config('outbound_notifications.types.'.$type->value.'.'.$channel->value, false) === true));
    }
}
