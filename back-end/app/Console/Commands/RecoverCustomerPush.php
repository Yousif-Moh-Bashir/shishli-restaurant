<?php

namespace App\Console\Commands;

use App\Services\Notifications\CustomerPushNotifications;
use Illuminate\Console\Command;

class RecoverCustomerPush extends Command
{
    protected $signature = 'customer-push:recover';

    protected $description = 'Recover only previously captured customer push deliveries, never historical events';

    public function handle(CustomerPushNotifications $push): int
    {
        $this->info('Queued '.$push->recover().' customer push deliveries.');

        return self::SUCCESS;
    }
}
