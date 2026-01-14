<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TestTelegramCommand extends Command
{
    protected $signature = 'telegram:test
                            {--message= : Custom test message}';

    protected $description = 'Test Telegram notifications';

    public function handle(): int
    {
        $this->info('Testing Telegram connection...');
        $this->line('');

        $telegram = new TelegramService();

        // Проверка конфигурации
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (!$botToken || !$chatId) {
            $this->error('Telegram not configured!');
            $this->line('');
            $this->line('Please add to .env:');
            $this->line('  TELEGRAM_BOT_TOKEN=your_bot_token');
            $this->line('  TELEGRAM_CHAT_ID=your_chat_id');
            $this->line('');
            $this->line('See TELEGRAM_SETUP.md for instructions.');
            return self::FAILURE;
        }

        $this->info('Configuration found:');
        $this->line("  Bot Token: " . substr($botToken, 0, 10) . '...');
        $this->line("  Chat ID: {$chatId}");
        $this->line('');

        // Отправка тестового сообщения
        $customMessage = $this->option('message');
        
        if ($customMessage) {
            $this->line("Sending custom message: {$customMessage}");
            $result = $telegram->sendMessage($customMessage);
            
            if ($result) {
                $this->info('✅ Message sent successfully!');
                $this->line('');
                $this->line('Check your Telegram to see the message.');
                return self::SUCCESS;
            } else {
                $this->error('❌ Failed to send message!');
                $this->line('');
                $this->showErrorHelp();
                return self::FAILURE;
            }
        } else {
            $this->line('Sending test messages...');
            $this->line('');

            $allSuccess = true;

            // Тест 1: Простое сообщение
            $this->line('1. Testing simple message...');
            $result1 = $telegram->sendMessage("✅ <b>Telegram Test</b>\n\nThis is a test message from your trading bot!");
            
            if ($result1) {
                $this->info('   ✓ Simple message sent successfully!');
            } else {
                $this->error('   ✗ Failed to send simple message');
                $allSuccess = false;
            }
            $this->line('');

            // Тест 2: BUY уведомление
            $this->line('2. Testing BUY notification...');
            $result2 = $telegram->sendMessage(
                "🟢 REAL <b>BUY ORDER</b>\n\n" .
                "Symbol: <b>BTCUSDT</b>\n" .
                "Amount: <b>10.0 USDT</b>\n" .
                "Price: <b>\$95000</b>\n" .
                "Time: " . now()->format('Y-m-d H:i:s')
            );
            if ($result2) {
                $this->info('   ✓ BUY notification sent!');
            } else {
                $this->error('   ✗ Failed to send BUY notification');
                $allSuccess = false;
            }
            $this->line('');

            // Тест 3: SELL уведомление
            $this->line('3. Testing SELL notification...');
            $result3 = $telegram->sendMessage(
                "🟢 REAL <b>SELL ORDER</b>\n\n" .
                "Symbol: <b>BTCUSDT</b>\n" .
                "Quantity: <b>0.0001</b>\n" .
                "Price: <b>\$96000</b>\n" .
                "Time: " . now()->format('Y-m-d H:i:s')
            );
            if ($result3) {
                $this->info('   ✓ SELL notification sent!');
            } else {
                $this->error('   ✗ Failed to send SELL notification');
                $allSuccess = false;
            }
            $this->line('');

            // Тест 4: FILLED уведомление
            $this->line('4. Testing FILLED notification...');
            $result4 = $telegram->sendMessage(
                "✅ <b>ORDER FILLED</b>\n\n" .
                "Side: <b>BUY</b>\n" .
                "Symbol: <b>BTCUSDT</b>\n" .
                "Quantity: <b>0.0001</b>\n" .
                "Price: <b>\$95000</b>\n" .
                "Fee: <b>0.00001</b>\n" .
                "Time: " . now()->format('Y-m-d H:i:s')
            );
            if ($result4) {
                $this->info('   ✓ FILLED notification sent!');
            } else {
                $this->error('   ✗ Failed to send FILLED notification');
                $allSuccess = false;
            }
            $this->line('');

            // Тест 5: Пропуск сделки
            $this->line('5. Testing SKIP notification...');
            $result5 = $telegram->sendMessage(
                "⚠️ <b>TRADE SKIPPED</b>\n\n" .
                "Action: <b>BUY</b>\n" .
                "Reason: Position already open\n" .
                "Time: " . now()->format('Y-m-d H:i:s')
            );
            if ($result5) {
                $this->info('   ✓ SKIP notification sent!');
            } else {
                $this->error('   ✗ Failed to send SKIP notification');
                $allSuccess = false;
            }
            $this->line('');

            // Тест 6: Закрытие позиции
            $this->line('6. Testing POSITION CLOSED notification...');
            $result6 = $telegram->sendMessage(
                "📈 <b>POSITION CLOSED</b>\n\n" .
                "Symbol: <b>BTCUSDT</b>\n" .
                "Buy Price: <b>\$95000</b>\n" .
                "Sell Price: <b>\$96000</b>\n" .
                "Quantity: <b>0.0001</b>\n" .
                "PnL: <b>1.0 USDT</b>\n" .
                "Time: " . now()->format('Y-m-d H:i:s')
            );
            if ($result6) {
                $this->info('   ✓ POSITION CLOSED notification sent!');
            } else {
                $this->error('   ✗ Failed to send POSITION CLOSED notification');
                $allSuccess = false;
            }
            $this->line('');

            if ($allSuccess) {
                $this->info('✅ All tests completed!');
                $this->line('');
                $this->line('Check your Telegram to see the notifications.');
                return self::SUCCESS;
            } else {
                $this->error('❌ Some tests failed!');
                $this->line('');
                $this->showErrorHelp();
                return self::FAILURE;
            }
        }
    }

    protected function showErrorHelp(): void
    {
        // Попытка получить последнюю ошибку из логов
        $lastError = \App\Services\TelegramService::getLastError();
        
        if ($lastError) {
            $this->warn('Last Telegram API error:');
            $this->line('  Error Code: ' . ($lastError['error_code'] ?? 'N/A'));
            $this->line('  Description: ' . ($lastError['description'] ?? 'N/A'));
            $this->line('');
            
            // Специфичные советы на основе кода ошибки
            $errorCode = $lastError['error_code'] ?? 0;
            $description = strtolower($lastError['description'] ?? '');
            
            if ($errorCode === 400) {
                if (str_contains($description, 'chat not found')) {
                    $this->error('❌ Chat not found!');
                    $this->line('');
                    $this->line('This usually means:');
                    $this->line('  1. The Chat ID is incorrect');
                    $this->line('  2. You haven\'t sent /start to your bot yet');
                    $this->line('  3. You\'re trying to message a group where the bot is not a member');
                    $this->line('');
                    $this->line('How to fix:');
                    $this->line('  1. Open Telegram and find your bot (search by username, e.g. @my_trading_bot)');
                    $this->line('  2. Click "START" button or send /start command to your bot');
                    $this->line('  3. Verify that bot replied with a welcome message');
                    $this->line('  4. Get your Chat ID using @userinfobot or Telegram API');
                    $this->line('  5. Update TELEGRAM_CHAT_ID in .env');
                    $this->line('  6. Run telegram:test again');
                } elseif (str_contains($description, 'invalid token')) {
                    $this->error('❌ Invalid bot token!');
                    $this->line('');
                    $this->line('Check your TELEGRAM_BOT_TOKEN in .env');
                    $this->line('Get a new token from @BotFather on Telegram');
                } else {
                    $this->error('❌ Bad Request: ' . ($lastError['description'] ?? 'Unknown error'));
                }
            } elseif ($errorCode === 401) {
                $this->error('❌ Unauthorized - Invalid bot token!');
                $this->line('');
                $this->line('Get a new token from @BotFather on Telegram');
            } elseif ($errorCode === 403) {
                $this->error('❌ Forbidden - Bot was blocked by user!');
                $this->line('');
                $this->line('Unblock the bot and send /start again');
            } elseif ($errorCode === 429) {
                $this->error('❌ Too many requests - Rate limit exceeded!');
                $this->line('');
                $this->line('Wait a few minutes and try again');
            }
        }
        
        $this->line('');
        $this->line('General troubleshooting:');
        $this->line('  1. Invalid bot token - check TELEGRAM_BOT_TOKEN in .env');
        $this->line('  2. Invalid chat ID - check TELEGRAM_CHAT_ID in .env');
        $this->line('  3. Bot not started - send /start to your bot first');
        $this->line('  4. Network issues - check internet connection');
        $this->line('  5. Check logs: tail -f storage/logs/laravel.log');
        $this->line('');
        $this->line('Verify your configuration:');
        $this->line('  - Bot token should start with a number');
        $this->line('  - Chat ID should be a number (can be negative for groups)');
        $this->line('  - Make sure you sent /start to your bot');
        $this->line('');
        $this->line('See TELEGRAM_SETUP.md for detailed instructions.');
    }
}
