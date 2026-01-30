<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Планировщик задач
Schedule::command('bots:run')->everyFiveMinutes();
Schedule::command('orders:sync')->everyMinute();
Schedule::command('telegram:heartbeat')->everyFiveMinutes();
Schedule::command('telegram:daily-stats')->dailyAt('09:00');
// Закрытие маленьких позиций (< 1 USDT) каждый день в 3:00
Schedule::command('positions:close-small')->dailyAt('17:26')->withoutOverlapping();
// Анализ производительности каждый день в 00:00
Schedule::command('stats:analyze --days=30')->dailyAt('00:00')->withoutOverlapping();

Schedule::command('futures:run')->everyFiveMinutes();