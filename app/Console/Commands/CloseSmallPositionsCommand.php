<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class CloseSmallPositionsCommand extends Command
{
    protected $signature = 'positions:close-small
        {--threshold=1.0 : Минимальная стоимость позиции в USDT (по умолчанию 1.0)}
        {--bot= : Закрыть только для конкретного бота (Bot ID)}
        {--symbol= : Закрыть только для конкретной пары (например, SOLUSDT)}
        {--sell : Продать на бирже перед закрытием в БД (по умолчанию только закрыть в БД)}
        {--dry-run : Показать позиции без закрытия}';

    protected $description = 'Закрыть маленькие позиции (< threshold USDT) в БД';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $botId = $this->option('bot');
        $symbol = $this->option('symbol');
        $sellOnExchange = $this->option('sell');
        $dryRun = $this->option('dry-run');

        if ($threshold <= 0) {
            $this->error('Threshold должен быть больше 0 (Threshold must be greater than 0)');
            return self::FAILURE;
        }

        $this->info("🔍 Поиск маленьких позиций (< {$threshold} USDT)...");
        $this->line('');

        // Строим запрос для поиска открытых BUY позиций
        $query = Trade::where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with(['bot.exchangeAccount']);

        // Фильтр по боту
        if ($botId) {
            $query->where('trading_bot_id', $botId);
        }

        // Фильтр по символу
        if ($symbol) {
            $query->where('symbol', strtoupper($symbol));
        }

        $openBuys = $query->get();

        if ($openBuys->isEmpty()) {
            $this->info('✅ Открытых позиций не найдено (No open positions found)');
            return self::SUCCESS;
        }

        $this->info("Найдено позиций: {$openBuys->count()}");
        $this->line('');

        $toClose = [];
        $totalValue = 0;

        // Группируем по биржевым аккаунтам для получения цен
        // ВАЖНО: Группируем по exchange_account_id, а не по bot_id,
        // чтобы правильно получить цену для каждой торговой пары
        $groupedByAccount = $openBuys->groupBy(function ($trade) {
            return $trade->bot->exchange_account_id ?? 0;
        });

        foreach ($groupedByAccount as $accountId => $trades) {
            if ($accountId === 0) {
                $this->warn("⚠️  Найдены позиции без биржевого аккаунта (Found positions without exchange account)");
                continue;
            }

            // Получаем первый бот для получения exchange account
            $firstTrade = $trades->first();
            $bot = $firstTrade->bot;
            
            if (!$bot || !$bot->exchangeAccount) {
                $this->warn("⚠️  Бот #{$bot->id} не найден или нет аккаунта биржи (Bot #{$bot->id} not found or no exchange account)");
                continue;
            }

            try {
                $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
            } catch (\Throwable $e) {
                $this->warn("⚠️  Ошибка создания сервиса биржи: {$e->getMessage()}");
                continue;
            }

            // Для каждой позиции получаем цену её символа
            foreach ($trades as $trade) {
                try {
                    // ВАЖНО: Используем символ позиции, а не символ бота!
                    $currentPrice = $exchangeService->getPrice($trade->symbol);
                    $valueUsdt = (float) $trade->quantity * $currentPrice;

                    if ($valueUsdt < $threshold) {
                        $toClose[] = [
                            'trade' => $trade,
                            'bot' => $trade->bot,
                            'value' => $valueUsdt,
                            'price' => $currentPrice,
                        ];
                        $totalValue += $valueUsdt;
                    }
                } catch (\Throwable $e) {
                    $this->warn("⚠️  Ошибка получения цены для {$trade->symbol}: {$e->getMessage()}");
                    continue;
                }
            }
        }

        if (empty($toClose)) {
            $this->info('✅ Маленьких позиций не найдено (No small positions found)');
            return self::SUCCESS;
        }

        $this->line(str_repeat('-', 50));

        // Показываем позиции для закрытия
        foreach ($toClose as $item) {
            $trade = $item['trade'];
            $bot = $item['bot'];
            $value = $item['value'];
            $price = $item['price'];

            $this->line("Bot #{$bot->id} | {$bot->symbol}");
            $this->line("  Позиция #{$trade->id}: {$trade->quantity} @ $" . number_format($trade->price, 2));
            $this->line("  Текущая цена: $" . number_format($price, 2));
            $this->line("  Стоимость: $" . number_format($value, 4) . " USDT");
            
            if ($dryRun) {
                $this->line("  🔍 [DRY-RUN] Будет закрыто");
            } else {
                $this->line("  ✅ Закрыто в БД");
            }
            $this->line('');
        }

        $this->line(str_repeat('-', 50));
        $this->info("Итого найдено: " . count($toClose) . " позиций");
        $this->info("Общая стоимость: $" . number_format($totalValue, 4) . " USDT");
        $this->line('');

        if ($dryRun) {
            $this->warn('🔍 DRY-RUN режим: позиции НЕ закрыты (DRY-RUN mode: positions NOT closed)');
            $this->info('Запустите без --dry-run для закрытия (Run without --dry-run to close)');
            return self::SUCCESS;
        }

        // Закрываем позиции
        $telegram = new TelegramService();
        $closedCount = 0;
        $soldCount = 0;

        foreach ($toClose as $item) {
            $trade = $item['trade'];
            $bot = $item['bot'];
            $value = $item['value'];
            $price = $item['price'];

            try {
                // Опционально продать на бирже
                if ($sellOnExchange) {
                    $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
                    // ВАЖНО: Используем символ позиции, а не символ бота!
                    $baseCoin = str_replace('USDT', '', $trade->symbol);
                    
                    try {
                        // Проверяем минимальный размер ордера
                        $minQuantity = $this->getMinQuantity($bot->exchangeAccount->exchange, $baseCoin);
                        
                        if ($trade->quantity >= $minQuantity) {
                            // Продаем на бирже (используем символ позиции)
                            $exchangeService->placeMarketSellBtc($trade->symbol, $trade->quantity);
                            $soldCount++;
                            $this->info("  ✅ Продано на бирже: {$trade->quantity} {$baseCoin} ({$trade->symbol})");
                        } else {
                            $this->warn("  ⚠️  Пропущена продажа: количество ({$trade->quantity}) меньше минимума ({$minQuantity})");
                        }
                    } catch (\Throwable $e) {
                        $this->warn("  ⚠️  Ошибка продажи на бирже: {$e->getMessage()}");
                        // Продолжаем закрывать в БД даже если продажа не удалась
                    }
                }

                // Закрываем в БД
                $trade->update([
                    'closed_at' => now(),
                    'realized_pnl' => 0, // PnL = 0 для маленьких позиций
                ]);

                $closedCount++;
            } catch (\Throwable $e) {
                $this->error("  ❌ Ошибка закрытия позиции #{$trade->id}: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info("✅ Закрыто позиций в БД: {$closedCount}");
        if ($sellOnExchange) {
            $this->info("✅ Продано на бирже: {$soldCount}");
        }
        $this->info("💰 Освобождено капитала: $" . number_format($totalValue, 4) . " USDT");

        // Отправляем уведомление в Telegram
        try {
            $message = "🧹 <b>ЗАКРЫТО МАЛЕНЬКИХ ПОЗИЦИЙ (SMALL POSITIONS CLOSED)</b>\n\n";
            $message .= "Закрыто позиций (Closed): <b>{$closedCount}</b>\n";
            $message .= "💰 Освобождено (Freed): <b>$" . number_format($totalValue, 4) . " USDT</b>\n";
            $message .= "📊 Порог (Threshold): < {$threshold} USDT";
            
            if ($sellOnExchange && $soldCount > 0) {
                $message .= "\n✅ Продано на бирже (Sold on exchange): <b>{$soldCount}</b>";
            }
            
            $message .= "\n\nВремя (Time): " . now()->format('Y-m-d H:i:s');
            
            $telegram->sendMessage($message);
        } catch (\Throwable $e) {
            // Игнорируем ошибки Telegram
        }

        return self::SUCCESS;
    }

    /**
     * Получить минимальное количество для ордера
     */
    private function getMinQuantity(string $exchange, string $baseCoin): float
    {
        // Минимальные размеры для OKX
        if ($exchange === 'okx') {
            $minQuantities = [
                'BTC' => 0.0001,
                'ETH' => 0.001,
                'SOL' => 0.01,
                'BNB' => 0.001,
                'ADA' => 1,
                'DOT' => 0.1,
                'LINK' => 0.1,
                'MATIC' => 1,
            ];

            return $minQuantities[strtoupper($baseCoin)] ?? 0.001;
        }

        // Минимальные размеры для Bybit
        if ($exchange === 'bybit') {
            $minQuantities = [
                'BTC' => 0.00001,
                'ETH' => 0.0001,
                'SOL' => 0.01,
            ];

            return $minQuantities[strtoupper($baseCoin)] ?? 0.001;
        }

        return 0.001; // По умолчанию
    }
}
