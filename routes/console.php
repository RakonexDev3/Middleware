<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run the queue worker automatically via cron every minute.
// It will process all pending webhooks and then stop, preventing memory leaks.
Schedule::command('queue:work --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();
