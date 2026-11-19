<?php

namespace App\Console\Commands;

use App\Services\Notifications\CustomerOrderNotifications;
use Illuminate\Console\Command;

class RecoverCustomerNotifications extends Command
{
    protected $signature = 'customer-notifications:recover';

    protected $description = 'Queue unpublished customer notification records without scanning historical orders';

    public function handle(CustomerOrderNotifications $notifications): int
    {
        $this->info('Queued for recovery: '.$notifications->recover());

        return self::SUCCESS;
    }
}
