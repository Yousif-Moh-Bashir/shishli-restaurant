<?php

namespace App\Console\Commands;

use App\Services\Notifications\StaffOrderNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staff-notifications:recover')]
#[Description('Recover pending new-order notification deliveries without scanning old orders')]
class RecoverStaffNotifications extends Command
{
    public function handle(StaffOrderNotifications $notifications): int
    {
        $this->info('Queued: '.$notifications->recover());

        return self::SUCCESS;
    }
}
