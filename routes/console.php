<?php

use App\Jobs\SendScheduledMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendScheduledMessage)->everyMinute();

// Real DNS verification: retry unverified domains hourly, confirm verified ones daily.
Schedule::command('domains:recheck')->hourly()->withoutOverlapping();
Schedule::command('domains:recheck --all')->dailyAt('03:17')->withoutOverlapping();
