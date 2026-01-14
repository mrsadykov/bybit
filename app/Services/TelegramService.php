<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private ?string $botToken;
    private ?string $chatId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
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
        $mode = $isDryRun ? '🔵 ТЕСТОВЫЙ РЕЖИМ (DRY RUN)' : '🟢 РЕАЛЬНАЯ СДЕЛКА (REAL)';
        $message = "{$mode} <b>ОРДЕР НА ПОКУПКУ (BUY ORDER)</b>\n\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Сумма (Amount): <b>{$amount} USDT</b>\n";
        $message .= "Цена (Price): <b>\${$price}</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о SELL ордере
     */
    public function notifySell(string $symbol, float $quantity, float $price, bool $isDryRun = false): void
    {
        $mode = $isDryRun ? '🔵 ТЕСТОВЫЙ РЕЖИМ (DRY RUN)' : '🟢 РЕАЛЬНАЯ СДЕЛКА (REAL)';
        $message = "{$mode} <b>ОРДЕР НА ПРОДАЖУ (SELL ORDER)</b>\n\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Количество (Quantity): <b>{$quantity}</b>\n";
        $message .= "Цена (Price): <b>\${$price}</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о попытке (пропуске) сделки
     */
    public function notifySkip(string $action, string $reason): void
    {
        $message = "⚠️ <b>СДЕЛКА ПРОПУЩЕНА (TRADE SKIPPED)</b>\n\n";
        $message .= "Действие (Action): <b>{$action}</b>\n";
        $message .= "Причина (Reason): {$reason}\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление об ошибке
     */
    public function notifyError(string $action, string $error): void
    {
        $message = "❌ <b>ОШИБКА (ERROR)</b>\n\n";
        $message .= "Действие (Action): <b>{$action}</b>\n";
        $message .= "Ошибка (Error): <code>{$error}</code>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление об успешном выполнении ордера
     */
    public function notifyFilled(string $side, string $symbol, float $quantity, float $price, float $fee = 0): void
    {
        $emoji = $side === 'BUY' ? '✅' : '💰';
        $sideText = $side === 'BUY' ? 'ПОКУПКА (BUY)' : 'ПРОДАЖА (SELL)';
        $message = "{$emoji} <b>ОРДЕР ИСПОЛНЕН (ORDER FILLED)</b>\n\n";
        $message .= "Сторона (Side): <b>{$sideText}</b>\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Количество (Quantity): <b>{$quantity}</b>\n";
        $message .= "Цена (Price): <b>\${$price}</b>\n";
        
        if ($fee > 0) {
            $message .= "Комиссия (Fee): <b>{$fee}</b>\n";
        }
        
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о запуске команды
     */
    public function notifyBotRunStart(int $botCount): void
    {
        $message = "🚀 <b>ЗАПУСК БОТОВ (BOTS RUN STARTED)</b>\n\n";
        $message .= "Активных ботов (Active bots): <b>{$botCount}</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Отправить уведомление о HOLD сигнале (No action taken)
     */
    public function notifyHold(string $symbol, float $price, string $signal, float $rsi = null, float $ema = null): void
    {
        $message = "⏸️ <b>ДЕЙСТВИЙ НЕ ПРЕДПРИНЯТО (NO ACTION TAKEN)</b>\n\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Цена (Price): <b>\${$price}</b>\n";
        $message .= "Сигнал (Signal): <b>{$signal}</b>\n";
        
        if ($rsi !== null) {
            $message .= "RSI: <b>" . round($rsi, 2) . "</b>\n";
        }
        if ($ema !== null) {
            $message .= "EMA: <b>" . round($ema, 2) . "</b>\n";
        }
        
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

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

        $message = "📊 <b>ЕЖЕДНЕВНАЯ СТАТИСТИКА (DAILY STATISTICS)</b>\n\n";
        $message .= "Дата (Date): <b>{$date}</b>\n\n";
        
        $message .= "💰 <b>PnL: {$pnlSign}" . number_format($totalPnL, 8) . " USDT</b> {$pnlEmoji}\n";
        $message .= "📊 Закрытых позиций (Closed Positions): <b>{$closedPositions}</b>\n";
        $message .= "📈 Прибыльных сделок (Winning Trades): <b>{$winningTrades}</b>\n";
        $message .= "📉 Убыточных сделок (Losing Trades): <b>{$losingTrades}</b>\n";
        $message .= "🎯 Процент побед (Win Rate): <b>{$winRate}%</b>\n";
        $message .= "📦 Всего сделок (Total Trades): <b>{$totalTrades}</b>\n";
        $message .= "🔓 Открытых позиций (Open Positions): <b>{$openPositions}</b>\n";
        $message .= "🤖 Активных ботов (Active Bots): <b>{$activeBots}</b>";

        $this->sendMessage($message);
    }
}
