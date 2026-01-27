<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramHeartbeatCommand extends Command
{
    protected $signature = 'telegram:heartbeat';
    protected $description = 'Send heartbeat to health chat (server up). Run via cron; if messages stop, server is down.';

    public function handle(): int
    {
        if (!config('services.telegram.health_chat_id')) {
            return self::SUCCESS;
        }

        $telegram = new TelegramService();
        $telegram->notifyHeartbeat();

        return self::SUCCESS;
    }
}
