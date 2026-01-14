<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GetTelegramChatIdCommand extends Command
{
    protected $signature = 'telegram:chat-id
                            {--token= : Bot token (optional, will use from .env if not provided)}';

    protected $description = 'Get Telegram Chat ID after sending /start to your bot';

    public function handle(): int
    {
        $this->info('Getting Telegram Chat ID...');
        $this->line('');

        // Получаем токен
        $token = $this->option('token') ?: config('services.telegram.bot_token');

        if (!$token) {
            $this->error('Bot token not found!');
            $this->line('');
            $this->line('Please provide token:');
            $this->line('  php artisan telegram:chat-id --token=YOUR_BOT_TOKEN');
            $this->line('');
            $this->line('Or set TELEGRAM_BOT_TOKEN in .env');
            return self::FAILURE;
        }

        $this->line('Bot Token: ' . substr($token, 0, 10) . '...');
        $this->line('');
        $this->warn('⚠️  IMPORTANT: Make sure you sent /start to your bot first!');
        $this->line('');
        $this->line('Steps:');
        $this->line('  1. Open Telegram');
        $this->line('  2. Find your bot (search by username)');
        $this->line('  3. Send /start command to your bot');
        $this->line('  4. Wait a few seconds');
        $this->line('  5. Press ENTER to continue...');

        if (!$this->confirm('Have you sent /start to your bot?', true)) {
            $this->line('');
            $this->warn('Please send /start to your bot first, then run this command again.');
            return self::FAILURE;
        }

        $this->line('');
        $this->line('Fetching updates from Telegram API...');
        $this->line('');

        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUpdates");

            if (!$response->successful()) {
                $this->error('Failed to connect to Telegram API');
                $this->line('Response: ' . $response->body());
                return self::FAILURE;
            }

            $data = $response->json();

            if (!$data || !isset($data['ok']) || !$data['ok']) {
                $this->error('Invalid response from Telegram API');
                $this->line('Response: ' . json_encode($data, JSON_PRETTY_PRINT));
                return self::FAILURE;
            }

            $updates = $data['result'] ?? [];

            if (empty($updates)) {
                $this->error('No updates found!');
                $this->line('');
                $this->line('This usually means:');
                $this->line('  1. You haven\'t sent /start to your bot yet');
                $this->line('  2. All updates were already retrieved (Telegram deletes them after reading)');
                $this->line('');
                $this->line('Solution:');
                $this->line('  1. Send /start to your bot RIGHT NOW');
                $this->line('  2. Wait 2-3 seconds');
                $this->line('  3. Run this command again immediately');
                $this->line('');
                $this->line('Or use @userinfobot to get your Chat ID:');
                $this->line('  1. Open @userinfobot in Telegram');
                $this->line('  2. Send any message');
                $this->line('  3. It will return your Chat ID');
                return self::FAILURE;
            }

            // Находим последнее сообщение с /start
            $chatIds = [];
            foreach ($updates as $update) {
                if (isset($update['message'])) {
                    $message = $update['message'];
                    if (isset($message['chat']['id'])) {
                        $chatId = $message['chat']['id'];
                        $chatType = $message['chat']['type'] ?? 'unknown';
                        $chatTitle = $message['chat']['title'] ?? $message['chat']['username'] ?? $message['chat']['first_name'] ?? 'Unknown';
                        
                        if (!in_array($chatId, array_column($chatIds, 'id'))) {
                            $chatIds[] = [
                                'id' => $chatId,
                                'type' => $chatType,
                                'title' => $chatTitle,
                                'username' => $message['chat']['username'] ?? null,
                            ];
                        }
                    }
                }
            }

            if (empty($chatIds)) {
                $this->error('No chat IDs found in updates');
                return self::FAILURE;
            }

            $this->info('✅ Found Chat IDs:');
            $this->line('');

            foreach ($chatIds as $chat) {
                $this->line("Chat ID: <fg=cyan>{$chat['id']}</>");
                $this->line("Type: {$chat['type']}");
                $this->line("Title/Name: {$chat['title']}");
                if ($chat['username']) {
                    $this->line("Username: @{$chat['username']}");
                }
                $this->line('');
            }

            $mainChatId = $chatIds[0]['id'];
            
            $this->info('💡 Your Chat ID: ' . $mainChatId);
            $this->line('');
            $this->line('Add this to your .env file:');
            $this->line('');
            $this->line("<fg=yellow>TELEGRAM_CHAT_ID={$mainChatId}</>");
            $this->line('');
            $this->line('Then run: php artisan telegram:test');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
