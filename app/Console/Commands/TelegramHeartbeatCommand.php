<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramHeartbeatCommand extends Command
{
    protected $signature = 'telegram:heartbeat {--verbose : Show detailed output}';
    protected $description = 'Send heartbeat to health chat (server up). Run via cron; if messages stop, server is down.';

    public function handle(): int
    {
        $verbose = $this->option('verbose');
        
        $healthChatId = config('services.telegram.health_chat_id');
        $healthBotToken = config('services.telegram.health_bot_token');
        $mainBotToken = config('services.telegram.bot_token');

        if ($verbose) {
            $this->info('Checking Telegram health heartbeat configuration...');
            $this->line("Health Chat ID: " . ($healthChatId ?: 'NOT SET'));
            $this->line("Health Bot Token: " . ($healthBotToken ? substr($healthBotToken, 0, 10) . '...' : 'NOT SET (will use main bot token)'));
            $this->line("Main Bot Token: " . ($mainBotToken ? substr($mainBotToken, 0, 10) . '...' : 'NOT SET'));
        }

        if (!$healthChatId) {
            if ($verbose) {
                $this->warn('TELEGRAM_HEALTH_CHAT_ID not set. Skipping heartbeat.');
                $this->line('To enable: set TELEGRAM_HEALTH_CHAT_ID in .env');
            }
            Log::debug('Telegram heartbeat skipped: TELEGRAM_HEALTH_CHAT_ID not set');
            return self::SUCCESS;
        }

        if (!$healthBotToken && !$mainBotToken) {
            if ($verbose) {
                $this->error('No bot token available (neither TELEGRAM_HEALTH_BOT_TOKEN nor TELEGRAM_BOT_TOKEN is set)');
            }
            Log::error('Telegram heartbeat failed: No bot token available');
            return self::FAILURE;
        }

        try {
            $telegram = new TelegramService();
            $result = $telegram->notifyHeartbeat();

            if ($verbose) {
                if ($result) {
                    $this->info('✅ Heartbeat sent successfully');
                } else {
                    $this->error('❌ Failed to send heartbeat');
                    $this->line('Check logs for details: storage/logs/laravel.log');
                    $this->line('');
                    $this->line('Troubleshooting:');
                    $this->line('  1. Check TELEGRAM_HEALTH_CHAT_ID in .env');
                    $this->line('  2. Check TELEGRAM_HEALTH_BOT_TOKEN or TELEGRAM_BOT_TOKEN in .env');
                    $this->line('  3. Verify bot token is valid');
                    $this->line('  4. Verify chat_id is correct (use @userinfobot)');
                }
            }

            if (!$result) {
                Log::warning('Telegram heartbeat: notifyHeartbeat returned false', [
                    'health_chat_id' => $healthChatId,
                    'has_health_bot_token' => !empty($healthBotToken),
                ]);
            } else {
                Log::debug('Telegram heartbeat sent successfully', [
                    'health_chat_id' => $healthChatId,
                    'has_health_bot_token' => !empty($healthBotToken),
                ]);
            }

            return $result ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if ($verbose) {
                $this->error('Exception: ' . $e->getMessage());
            }
            Log::error('Telegram heartbeat exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}
