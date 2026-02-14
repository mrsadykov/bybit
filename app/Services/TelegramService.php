<?php

namespace App\Services;

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /** Режим «одно сообщение за цикл»: уведомления накапливаются в batch и отправляются одним вызовом sendBatch(). */
    private static bool $batchMode = false;

    /** Накопленные строки для одного сообщения (разделы разделяются через —————). */
    private static array $batchLines = [];

    private ?string $botToken;
    private ?string $chatId;
    private ?string $healthBotToken;
    private ?string $healthChatId;

    public static function setBatchMode(bool $on): void
    {
        self::$batchMode = $on;
        if (! $on) {
            self::$batchLines = [];
        }
    }

    public static function getBatchMode(): bool
    {
        return self::$batchMode;
    }

    /**
     * Отправить накопленное сообщение и очистить буфер. Вызывать в конце цикла (например из trading:run-all).
     */
    public function sendBatch(): bool
    {
        if (self::$batchLines === []) {
            return true;
        }
        $separator = "\n\n—————\n\n";
        $message = implode($separator, self::$batchLines);
        self::$batchLines = [];
        self::$batchMode = false;

        return $this->sendMessage($message);
    }

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
        $this->healthBotToken = config('services.telegram.health_bot_token');
        $this->healthChatId = config('services.telegram.health_chat_id');
    }

    /**
     * Отправить сообщение в Telegram (синхронно или в очередь, если включено).
     */
    public function sendMessage(string $message, ?string $parseMode = 'HTML'): bool
    {
        if (config('services.telegram.queue')) {
            SendTelegramMessageJob::dispatch($message, $parseMode, null, null);

            return true;
        }

        return $this->sendMessageSync($message, $parseMode, null, null);
    }

    /**
     * Синхронная отправка в Telegram (используется Job'ом или при отключённой очереди).
     *
     * @param  string|null  $chatId  null = основной chat_id из конфига
     * @param  string|null  $botToken  null = основной bot_token из конфига
     */
    public function sendMessageSync(string $message, ?string $parseMode = 'HTML', ?string $chatId = null, ?string $botToken = null): bool
    {
        $targetChatId = $chatId ?? $this->chatId;
        $targetToken = $botToken ?? $this->botToken;

        if (! $targetToken || ! $targetChatId) {
            Log::warning('Telegram not configured: bot_token or chat_id missing');

            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$targetToken}/sendMessage", [
                'chat_id' => $targetChatId,
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
     * Отправить уведомление о попытке (пропуске) сделки.
     *
     * @param  string  $action  BUY или SELL
     * @param  string  $reason  Причина пропуска
     * @param  string|null  $symbol  Торговая пара (например BTCUSDT)
     */
    public function notifySkip(string $action, string $reason, ?string $symbol = null): void
    {
        $symbolLine = $symbol !== null ? "Символ (Symbol): <b>{$symbol}</b>\n" : '';

        if (self::$batchMode) {
            $line = "⚠️ <b>СДЕЛКА ПРОПУЩЕНА (TRADE SKIPPED)</b>\n\n";
            $line .= $symbolLine;
            $line .= "Действие (Action): <b>{$action}</b>\n";
            $line .= "Причина (Reason): {$reason}\n";
            $line .= "Время (Time): " . now()->format('Y-m-d H:i:s');
            self::$batchLines[] = $line;

            return;
        }

        $message = "⚠️ <b>СДЕЛКА ПРОПУЩЕНА (TRADE SKIPPED)</b>\n\n";
        $message .= $symbolLine;
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
     * Отправить уведомление о запуске спотовых ботов.
     */
    public function notifyBotRunStart(int $botCount): void
    {
        $message = "🚀 <b>СПОТ: ЗАПУСК БОТОВ (SPOT BOTS RUN STARTED)</b>\n\n";
        $message .= "Спотовые боты (Spot bots): <b>{$botCount}</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        if (self::$batchMode) {
            self::$batchLines[] = $message;

            return;
        }
        $this->sendMessage($message);
    }

    /**
     * Запуск фьючерсных ботов (Futures bots run started).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FuturesBot>|array  $bots  Коллекция ботов для вывода пар и режима (dry_run / real)
     */
    public function notifyFuturesRunStart(int $botCount, $bots = []): void
    {
        $message = "📈 <b>ФЬЮЧЕРСЫ: ЗАПУСК БОТОВ (FUTURES BOTS RUN STARTED)</b>\n\n";
        $message .= "Активных ботов (Active bots): <b>{$botCount}</b>\n";
        if (is_countable($bots) && count($bots) > 0) {
            $pairs = [];
            foreach ($bots as $bot) {
                $mode = ($bot->dry_run ?? true) ? 'dry_run' : 'real';
                $pairs[] = "{$bot->symbol} ({$mode})";
            }
            $message .= "Пары и режим (Pairs & mode): " . implode(', ', $pairs) . "\n";
        }
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        if (self::$batchMode) {
            self::$batchLines[] = $message;

            return;
        }
        $this->sendMessage($message);
    }

    /**
     * Сделка по фьючерсам (Futures trade)
     */
    public function notifyFuturesTrade(string $symbol, string $side, float $price, float $quantity, ?float $realizedPnl = null): void
    {
        $message = "📈 <b>ФЬЮЧЕРС (FUTURES)</b>\n\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Действие (Action): <b>{$side}</b>\n";
        $message .= "Цена (Price): <b>\${$price}</b>\n";
        $message .= "Количество контрактов (Contracts): <b>{$quantity}</b>\n";
        if ($realizedPnl !== null) {
            $message .= "Реализованный PnL (Realized PnL): <b>" . round($realizedPnl, 2) . " USDT</b>\n";
        }
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }

    /**
     * Запуск ботов за BTC (BTC-quote bots run started).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\BtcQuoteBot>|array  $bots  Коллекция ботов для вывода пар и режима (dry_run / real)
     */
    public function notifyBtcQuoteRunStart(int $botCount, $bots = []): void
    {
        $message = "₿ <b>BTC-QUOTE: ЗАПУСК БОТОВ (BTC-QUOTE BOTS RUN STARTED)</b>\n\n";
        $message .= "Активных ботов (Active bots): <b>{$botCount}</b>\n";
        if (is_countable($bots) && count($bots) > 0) {
            $pairs = [];
            foreach ($bots as $bot) {
                $mode = ($bot->dry_run ?? true) ? 'dry_run' : 'real';
                $pairs[] = "{$bot->symbol} ({$mode})";
            }
            $message .= "Пары и режим (Pairs & mode): " . implode(', ', $pairs) . "\n";
        }
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        if (self::$batchMode) {
            self::$batchLines[] = $message;

            return;
        }
        $this->sendMessage($message);
    }

    /**
     * Сделка по парам к BTC (BTC-quote trade)
     */
    public function notifyBtcQuoteTrade(string $symbol, string $side, float $priceBtc, float $quantity, ?float $realizedPnlBtc = null): void
    {
        $message = "₿ <b>БОТ ЗА BTC (BTC-QUOTE)</b>\n\n";
        $message .= "Символ (Symbol): <b>{$symbol}</b>\n";
        $message .= "Действие (Action): <b>{$side}</b>\n";
        $message .= "Цена (Price): <b>{$priceBtc} BTC</b>\n";
        $message .= "Количество (Quantity): <b>{$quantity}</b>\n";
        if ($realizedPnlBtc !== null) {
            $message .= "Реализованный PnL (Realized PnL): <b>" . round($realizedPnlBtc, 8) . " BTC</b>\n";
        }
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

        if (self::$batchMode) {
            self::$batchLines[] = $message;

            return;
        }
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
     * Алерт: дневной убыток превысил лимит
     */
    public function notifyAlertDailyLoss(float $dailyLossUsdt, float $limitUsdt): void
    {
        $message = "⚠️ <b>АЛЕРТ: ДНЕВНОЙ УБЫТОК (DAILY LOSS ALERT)</b>\n\n";
        $message .= "Дневной PnL (Daily PnL): <b>" . number_format($dailyLossUsdt, 2) . " USDT</b>\n";
        $message .= "Лимит (Limit): <b>" . number_format($limitUsdt, 2) . " USDT</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Алерт: серия убыточных сделок подряд
     */
    public function notifyAlertLosingStreak(int $streakCount, int $limit): void
    {
        $message = "⚠️ <b>АЛЕРТ: СЕРИЯ УБЫТКОВ (LOSING STREAK ALERT)</b>\n\n";
        $message .= "Убыточных сделок подряд (Losing trades in a row): <b>{$streakCount}</b>\n";
        $message .= "Лимит (Limit): <b>{$limit}</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Алерт: достигнута целевая прибыль
     */
    public function notifyAlertTargetProfit(float $totalPnLUsdt, float $targetUsdt): void
    {
        $message = "🎯 <b>ЦЕЛЕВАЯ ПРИБЫЛЬ ДОСТИГНУТА (TARGET PROFIT REACHED)</b>\n\n";
        $message .= "Суммарный PnL (Total PnL): <b>+" . number_format($totalPnLUsdt, 2) . " USDT</b>\n";
        $message .= "Цель (Target): <b>" . number_format($targetUsdt, 2) . " USDT</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Риск: торговля по боту приостановлена — достигнут лимит дневного убытка
     */
    public function notifyRiskLimitDailyLoss(string $symbol, float $dailyLossUsdt, float $limitUsdt): void
    {
        $message = "🛑 <b>РИСК: ЛИМИТ ДНЕВНОГО УБЫТКА (DAILY LOSS LIMIT)</b>\n\n";
        $message .= "Бот (Bot): <b>{$symbol}</b>\n";
        $message .= "Дневной PnL (Daily PnL): <b>" . number_format($dailyLossUsdt, 2) . " USDT</b>\n";
        $message .= "Лимит (Limit): <b>" . number_format($limitUsdt, 2) . " USDT</b>\n";
        $message .= "Торговля по боту приостановлена до завтра. Время: " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Риск: торговля по боту приостановлена — превышена максимальная просадка
     */
    public function notifyRiskLimitDrawdown(string $symbol, float $drawdownPercent, float $limitPercent): void
    {
        $message = "🛑 <b>РИСК: ЛИМИТ ПРОСАДКИ (DRAWDOWN LIMIT)</b>\n\n";
        $message .= "Бот (Bot): <b>{$symbol}</b>\n";
        $message .= "Просадка (Drawdown): <b>" . number_format($drawdownPercent, 2) . "%</b>\n";
        $message .= "Лимит (Limit): <b>" . number_format($limitPercent, 2) . "%</b>\n";
        $message .= "Торговля по боту приостановлена. Время: " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Алерт: дневной убыток по фьючерсным ботам превысил лимит
     */
    public function notifyFuturesDailyLossAlert(float $dailyPnLUsdt, float $limitUsdt): void
    {
        $message = "⚠️ <b>ФЬЮЧЕРСЫ: ДНЕВНОЙ УБЫТОК (FUTURES DAILY LOSS ALERT)</b>\n\n";
        $message .= "Дневной PnL (Daily PnL): <b>" . number_format($dailyPnLUsdt, 2) . " USDT</b>\n";
        $message .= "Лимит (Limit): <b>" . number_format($limitUsdt, 2) . " USDT</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Алерт: дневной убыток по BTC-quote ботам превысил лимит
     */
    public function notifyBtcQuoteDailyLossAlert(float $dailyPnLBtc, float $limitBtc): void
    {
        $message = "⚠️ <b>BTC-QUOTE: ДНЕВНОЙ УБЫТОК (BTC-QUOTE DAILY LOSS ALERT)</b>\n\n";
        $message .= "Дневной PnL (Daily PnL): <b>" . number_format($dailyPnLBtc, 8) . " BTC</b>\n";
        $message .= "Лимит (Limit): <b>" . number_format($limitBtc, 8) . " BTC</b>\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Короткое уведомление: бот пропущен в этом запуске из‑за лимита риска (не чаще 1 раза в час).
     */
    public function notifyBotSkippedRiskLimit(string $symbol): void
    {
        $message = "⏭️ <b>Бот пропущен (Bot skipped)</b>: {$symbol} — лимит риска (risk limit). Время: " . now()->format('H:i');
        $this->sendMessage($message);
    }

    /**
     * Риск: новый BUY не выставлен — достигнут лимит открытых позиций
     */
    public function notifyRiskLimitMaxPositions(string $symbol, int $currentCount, int $limit): void
    {
        $message = "🛑 <b>РИСК: ЛИМИТ ОТКРЫТЫХ ПОЗИЦИЙ (MAX OPEN POSITIONS)</b>\n\n";
        $message .= "Бот (Bot): <b>{$symbol}</b> — BUY пропущен\n";
        $message .= "Открытых позиций (Open positions): <b>{$currentCount}</b> / {$limit}\n";
        $message .= "Время: " . now()->format('Y-m-d H:i:s');
        $this->sendMessage($message);
    }

    /**
     * Heartbeat: «сервер работает». Вызывается по расписанию (например, каждые 5 мин).
     * Если сообщения перестают приходить — сервер, скорее всего, упал.
     * 
     * @return bool true если сообщение отправлено успешно, false в противном случае
     */
    public function notifyHeartbeat(): bool
    {
        $message = "🟢 <b>СЕРВЕР РАБОТАЕТ (SERVER UP)</b>\n\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        return $this->sendToHealthChat($message);
    }

    /**
     * Алерт проверки здоровья системы (health-check).
     * Отправляет в health-чат, если настроен, иначе в основной чат.
     */
    public function notifyHealthAlert(string $title, string $details): bool
    {
        $message = "⚠️ <b>HEALTH CHECK: {$title}</b>\n\n";
        $message .= $details . "\n\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        if (config('services.telegram.health_chat_id')) {
            return $this->sendToHealthChat($message);
        }

        return $this->sendMessageSync($message);
    }

    /**
     * Алерт сбоя бота (таймаут/ошибка API). Отправляет в health-чат или основной чат.
     */
    public function notifyBotError(string $botType, string $symbol, string $errorMessage): bool
    {
        $message = "⚠️ <b>Ошибка бота (Bot error)</b>\n\n";
        $message .= "Тип (Type): {$botType}\n";
        $message .= "Символ (Symbol): {$symbol}\n";
        $message .= "Ошибка (Error): " . \Illuminate\Support\Str::limit($errorMessage, 200) . "\n\n";
        $message .= "Время (Time): " . now()->format('Y-m-d H:i:s');

        if (config('services.telegram.health_chat_id')) {
            return $this->sendToHealthChat($message);
        }

        return $this->sendMessageSync($message);
    }

    /**
     * Отправить алерт об ошибке бота не чаще раза в час по одному боту (по cache key).
     */
    public static function notifyBotErrorOnce(string $botType, string $symbol, string $errorMessage, int $botId): void
    {
        $cacheKey = 'bot_error_alert_' . $botType . '_' . $botId . '_' . now()->format('Y-m-d-H');
        if (Cache::has($cacheKey)) {
            return;
        }
        try {
            (new self())->notifyBotError($botType, $symbol, $errorMessage);
            Cache::put($cacheKey, true, now()->addHour());
        } catch (\Throwable $e) {
            Log::warning('Telegram bot error alert failed', ['bot_id' => $botId, 'error' => $e->getMessage()]);
        }
    }
}
