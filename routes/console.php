<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:expire')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('subscriptions:remind')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('storage:prune-orphans')->dailyAt('03:30')->withoutOverlapping();
// The shared demo goes back to its sample data every night; expired demo visits are cleared just before.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:50')->withoutOverlapping();
Schedule::command('demo:reset')->dailyAt('04:00')->withoutOverlapping();
