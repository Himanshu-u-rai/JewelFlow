<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:run')->daily();
Schedule::command('loyalty:expire')->daily();
Schedule::command('subscription:check-expiry')->daily();
Schedule::command('scan:cleanup')->daily();
Schedule::command('cache:warm-shops')->everyTenMinutes()->withoutOverlapping();
Schedule::command('dhiran:accrue-interest')->daily();
Schedule::command('dhiran:overdue-reminders')->daily();
Schedule::command('dhiran:forfeiture-check')->daily();
