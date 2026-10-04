<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('lena:reconcile-speech-usage')->everyMinute()->withoutOverlapping();
// Each connector keeps its own interval; the command only runs the ones that are due.
Schedule::command('accounting:pantheon-sync')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
