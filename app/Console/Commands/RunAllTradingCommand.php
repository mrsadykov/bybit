<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Запускает спотовых, фьючерсных и BTC-quote ботов по очереди
 * и отправляет в Telegram одно сводное сообщение (разделы разделены —————).
 * По расписанию вызывается вместо отдельных bots:run, futures:run, btc-quote:run.
 */
class RunAllTradingCommand extends Command
{
    protected $signature = 'trading:run-all';
    protected $description = 'Run spot, futures and BTC-quote bots; send one combined Telegram message';

    public function handle(): int
    {
        $telegram = new TelegramService();
        TelegramService::setBatchMode(true);

        Artisan::call('bots:run');
        Artisan::call('futures:run');
        Artisan::call('btc-quote:run');

        $telegram->sendBatch();

        return self::SUCCESS;
    }
}
