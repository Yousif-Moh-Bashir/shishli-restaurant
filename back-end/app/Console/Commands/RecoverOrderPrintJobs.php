<?php

namespace App\Console\Commands;

use App\Services\Printing\AcceptedOrderPrinting;
use Illuminate\Console\Command;

class RecoverOrderPrintJobs extends Command
{
    protected $signature = 'printing:recover';

    protected $description = 'Recover missing print jobs from post-activation acceptance history';

    public function handle(AcceptedOrderPrinting $printing): int
    {
        $this->info('Recovered acceptance records: '.$printing->recover());

        return self::SUCCESS;
    }
}
