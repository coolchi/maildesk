<?php

use App\Jobs\DetectWorkspaceAbuse;
use App\Jobs\SendScheduledBroadcast;
use App\Jobs\SendScheduledMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendScheduledMessage)->everyMinute();
Schedule::job(new SendScheduledBroadcast)->everyMinute();

// Real DNS verification: retry unverified domains hourly, confirm verified ones daily.
Schedule::command('domains:recheck')->hourly()->withoutOverlapping();
Schedule::command('domains:recheck --all')->dailyAt('03:17')->withoutOverlapping();

// Gmail-style: permanently delete conversations left in Trash past retention.
Schedule::command('inbox:purge-trash')->dailyAt('03:40')->withoutOverlapping();

// Clean up orphaned temporary attachment uploads (files not linked to a sent message).
Schedule::command('attachments:purge-tmp')->dailyAt('04:00')->withoutOverlapping();

// End free trials → past_due lockout until Monipay payment.
Schedule::command('billing:expire-trials')->hourly()->withoutOverlapping();

// Scan active workspaces for bounce/volume/webhook abuse signals.
Schedule::job(new DetectWorkspaceAbuse)->hourly()->withoutOverlapping();
