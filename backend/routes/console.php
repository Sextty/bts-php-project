<?php

use App\Services\OperationalHeartbeatService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(OperationalHeartbeatService::class)->touchScheduler())
    ->name('operations:scheduler-heartbeat')
    ->everyMinute();

Schedule::command('audit:verify-integrity')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('outbox:dispatch --limit=1000')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:reconcile --limit=1000')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
