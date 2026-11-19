<?php

namespace App\Services\Notifications;

use GuzzleHttp\Client;
use Minishlink\WebPush\WebPush;

class CustomerWebPushTransport extends StaffWebPushTransport
{
    public function ready(): bool
    {
        return (bool) config('customer_push.public_key') && (bool) config('customer_push.private_key') && (bool) config('customer_push.subject');
    }

    public function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => config('customer_push.subject'), 'publicKey' => config('customer_push.public_key'),
            'privateKey' => config('customer_push.private_key'),
        ]], [], new Client(['connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false]));
    }
}
