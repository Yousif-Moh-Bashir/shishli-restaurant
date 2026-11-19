<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('staff-notifications:recover')->everyMinute()->withoutOverlapping();
Schedule::command('printing:recover')->everyMinute()->withoutOverlapping();
Schedule::command('customer-notifications:recover')->everyMinute()->withoutOverlapping();
Schedule::command('customer-push:recover')->everyMinute()->withoutOverlapping();
Schedule::command('delivery-progress:check')->everyMinute()->withoutOverlapping(5);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
