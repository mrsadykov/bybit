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

            Log::error('Telegram API error', [
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Telegram send error', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Отправить уведомление о BUY ордере
     */
    public function notifyBuy(string $symbol, float $amount, float $price, bool $isDryRun = false): void
    {
        $mode = $isDryRun ? '🔵 DRY RUN' : '🟢 REAL';
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
        $mode = $isDryRun ? '🔵 DRY RUN' : '🟢 REAL';
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
        $message = "{$emoji} <b>ORDER FILLED</b>\n\n";
        $message .= "Side: <b>{$side}</b>\n";
        $message .= "Symbol: <b>{$symbol}</b>\n";
        $message .= "Quantity: <b>{$quantity}</b>\n";
        $message .= "Price: <b>\${$price}</b>\n";
        
        if ($fee > 0) {
            $message .= "Fee: <b>{$fee}</b>\n";
        }
        
        $message .= "Time: " . now()->format('Y-m-d H:i:s');

        $this->sendMessage($message);
    }
}
