<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private ?string $botToken;
    private ?string $chatId;
    private ?string $healthBotToken;
    private ?string $healthChatId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
        $this->healthBotToken = config('services.telegram.health_bot_token');
        $this->healthChatId = config('services.telegram.health_chat_id');
    }

    /**
     * Отправить сообщение в Telegram
     */
    public function sendMessage(string $message, ?string $parseMode = 'HTML'): bool
    {
        if (!$this->botToken || !$this->chatId) {
            Log::warning('Telegram not configured: bot_token or chat_id missing');
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => $parseMode,
            ]);

            if ($response->successful() && ($response->json()['ok'] ?? false)) {
                return true;
            }

            $errorData = $response->json();
            Log::error('Telegram API error', [
                'response' => $errorData,
                'error_code' => $errorData['error_code'] ?? null,
                'description' => $errorData['description'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Telegram send error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Получить последнюю ошибку Telegram API из логов
     */
    public static function getLastError(): ?array
    {
        $logPath = storage_path('logs/laravel.log');
        if (!file_exists($logPath)) {
            return null;
        }

        // Читаем последние 50KB логов для поиска ошибки
        $fileSize = filesize($logPath);
        $readSize = min(50000, $fileSize);
        $handle = fopen($logPath, 'r');
        fseek($handle, -$readSize, SEEK_END);
        $logContent = fread($handle, $readSize);
        fclose($handle);

        // Ищем последнюю ошибку Telegram API
        if (preg_match('/Telegram API error.*?"response":\s*({[^}]*"error_code"[^}]*})/s', $logContent, $matches)) {
            try {
                $errorData = json_decode($matches[1], true);
                if ($errorData && isset($errorData['error_code'])) {
                    return $errorData;
                }
            } catch (\Exception $e) {
                // Если не удалось распарсить JSON, попробуем извлечь вручную
                if (preg_match('/"error_code":\s*(\d+)/', $matches[1], $codeMatch)) {
                    $description = '';
                    if (preg_match('/"description":\s*"([^"]+)"/', $matches[1], $descMatch)) {
                        $description = $descMatch[1];
                    }
                    return [
                        'error_code' => (int)$codeMatch[1],
                        'description' => $description,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Отправить уведомление о BUY ордере
     */
    public function notifyBuy(string $symbol, float $amount, float $price, bool $isDryRun = false): void
    {
        $mode = $isDryRun ? '🔵 DRY RUN' : '🟢 REAL TRADE';
        $message = "{$mode} <b>BUY ORDER</b>\n\n";
        $message .= "Symbol: <b>{$symbol}</b>\n";
        $message .= "Amount: <b>{$amount} USDT</b>\n";
        $message .= "Price: <b>\${$price}</b>\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о SELL ордере
     */
    public function notifySell(string $symbol, float $quantity, float $price, bool $isDryRun = false): void
    {
        $mode = $isDryRun ? '🔵 DRY RUN' : '🟢 REAL TRADE';
        $message = "{$mode} <b>SELL ORDER</b>\n\n";
        $message .= "Symbol: <b>{$symbol}</b>\n";
        $message .= "Quantity: <b>{$quantity}</b>\n";
        $message .= "Price: <b>\${$price}</b>\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о попытке (пропуске) сделки
     */
    public function notifySkip(string $action, string $reason): void
    {
        $message = "⚠️ <b>TRADE SKIPPED</b>\n\n";
        $message .= "Action: <b>{$action}</b>\n";
        $message .= "Reason: {$reason}\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление об ошибке
     */
    public function notifyError(string $action, string $error): void
    {
        $message = "❌ <b>ERROR</b>\n\n";
        $message .= "Action: <b>{$action}</b>\n";
        $message .= "Error: <code>{$error}</code>\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление об успешном выполнении ордера
     */
    public function notifyFilled(string $side, string $symbol, float $quantity, float $price, float $fee = 0): void
    {
        $emoji = $side === 'BUY' ? '✅' : '💰';
        $sideText = $side === 'BUY' ? 'BUY' : 'SELL';
        $message = "{$emoji} <b>ORDER FILLED</b>\n\n";
        $message .= "Side: <b>{$sideText}</b>\n";
        $message .= "Symbol: <b>{$symbol}</b>\n";
        $message .= "Quantity: <b>{$quantity}</b>\n";
        $message .= "Price: <b>\${$price}</b>\n";
        
        if ($fee > 0) {
            $message .= "Fee: <b>{$fee}</b>\n";
        }
        
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о запуске команды
     */
    public function notifyBotRunStart(int $botCount): void
    {
        $message = "🚀 <b>BOTS RUN STARTED</b>\n\n";
        $message .= "Active bots: <b>{$botCount}</b>\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о HOLD сигнале (No action taken)
     */
    public function notifyHold(string $symbol, float $price, string $signal, float $rsi = null, float $ema = null): void
    {
        $message = "⏸️ <b>NO ACTION TAKEN</b>\n\n";
        $message .= "Symbol: <b>{$symbol}</b>\n";
        $message .= "Price: <b>\${$price}</b>\n";
        $message .= "Signal: <b>{$signal}</b>\n";
        
        if ($rsi !== null) {
            $message .= "RSI: <b>" . round($rsi, 2) . "</b>\n";
        }
        if ($ema !== null) {
            $message .= "EMA: <b>" . round($ema, 2) . "</b>\n";
        }
        
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить ежедневную статистику
     */
    public function notifyDailyStats(array $stats): void
    {
        $date = $stats['date'] ?? now()->format('Y-m-d');
        $totalPnL = $stats['total_pnl'] ?? 0;
        $winningTrades = $stats['winning_trades'] ?? 0;
        $losingTrades = $stats['losing_trades'] ?? 0;
        $totalTrades = $stats['total_trades'] ?? 0;
        $winRate = $stats['win_rate'] ?? 0;
        $closedPositions = $stats['closed_positions'] ?? 0;
        $openPositions = $stats['open_positions'] ?? 0;
        $activeBots = $stats['active_bots'] ?? 0;

        $pnlEmoji = $totalPnL >= 0 ? '📈' : '📉';
        $pnlSign = $totalPnL >= 0 ? '+' : '';

        $message = "📊 <b>DAILY STATISTICS</b>\n\n";
        $message .= "Date: <b>{$date}</b>\n\n";
        
        $message .= "💰 <b>PnL: {$pnlSign}" . number_format($totalPnL, 8) . " USDT</b> {$pnlEmoji}\n";
        $message .= "📊 Closed Positions: <b>{$closedPositions}</b>\n";
        $message .= "📈 Winning Trades: <b>{$winningTrades}</b>\n";
        $message .= "📉 Losing Trades: <b>{$losingTrades}</b>\n";
        $message .= "🎯 Win Rate: <b>{$winRate}%</b>\n";
        $message .= "📦 Total Trades: <b>{$totalTrades}</b>\n";
        $message .= "🔓 Open Positions: <b>{$openPositions}</b>\n";
        $message .= "🤖 Active Bots: <b>{$activeBots}</b>";

        $this->sendMessage($message);
    }

    /**
     * Отправить сообщение в отдельный чат «мониторинг сервера» (heartbeat).
     * Использует отдельный токен health-бота, если задан TELEGRAM_HEALTH_BOT_TOKEN.
     * Если TELEGRAM_HEALTH_CHAT_ID не задан — отправка не выполняется.
     */
    public function sendToHealthChat(string $message, ?string $parseMode = 'HTML'): bool
    {
        // Используем отдельный токен для health-бота, если задан, иначе основной токен
        $token = $this->healthBotToken ?: $this->botToken;
        
        if (!$token) {
            Log::warning('Telegram health chat: No bot token available (neither health_bot_token nor main bot_token)');
            return false;
        }
        
        if (!$this->healthChatId) {
            Log::warning('Telegram health chat: TELEGRAM_HEALTH_CHAT_ID not set');
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $this->healthChatId,
                'text' => $message,
                'parse_mode' => $parseMode ?? 'HTML',
            ]);

            $isOk = $response->successful() && ($response->json()['ok'] ?? false);
            
            if (!$isOk) {
                $errorData = $response->json();
                Log::error('Telegram health chat API error', [
                    'response' => $errorData,
                    'error_code' => $errorData['error_code'] ?? null,
                    'description' => $errorData['description'] ?? null,
                    'chat_id' => $this->healthChatId,
                    'has_health_bot_token' => !empty($this->healthBotToken),
                ]);
            }

            return $isOk;
        } catch (\Throwable $e) {
            Log::error('Telegram health chat send error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chat_id' => $this->healthChatId,
            ]);
            return false;
        }
    }

    /**
     * Heartbeat: «сервер работает». Вызывается по расписанию (например, каждые 5 мин).
     * Если сообщения перестают приходить — сервер, скорее всего, упал.
     * 
     * @return bool true если сообщение отправлено успешно, false в противном случае
     */
    public function notifyHeartbeat(): bool
    {
        $message = "🟢 <b>SERVER UP</b>\n\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        return $this->sendToHealthChat($message);
    }
}
