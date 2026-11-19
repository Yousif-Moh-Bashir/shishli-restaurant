<?php

namespace App\Console\Commands;

use App\Services\Drivers\DeliveryProgressService;
use Illuminate\Console\Command;

class CheckDeliveryProgress extends Command
{
    protected $signature = 'delivery-progress:check';

    protected $description = 'Record delivery reminders and escalations without changing orders or sending external messages';

    public function handle(DeliveryProgressService $progress): int
    {
        $this->info('Checked '.$progress->scan().' delivery tasks.');

        return self::SUCCESS;
    }
}
