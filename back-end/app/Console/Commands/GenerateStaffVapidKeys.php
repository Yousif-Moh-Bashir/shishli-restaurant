<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('staff-notifications:vapid')]
#[Description('Generate VAPID keys for manual secure environment configuration')]
class GenerateStaffVapidKeys extends Command
{
    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('STAFF_WEB_PUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('STAFF_WEB_PUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
