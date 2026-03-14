<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pipeline:daily-notices --headless --continue-on-crawler-error')
    ->dailyAt((string) config('app.pipeline.daily_time', '00:10'))
    ->timezone((string) config('app.pipeline.timezone', 'Europe/Madrid'))
    ->when(fn (): bool => (bool) config('app.pipeline.daily_enabled', true))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('notice-digests:send')
    ->dailyAt((string) config('app.notifications.digest_time', '08:00'))
    ->timezone((string) config('app.notifications.timezone', config('app.pipeline.timezone', 'Europe/Madrid')))
    ->withoutOverlapping()
    ->runInBackground();
