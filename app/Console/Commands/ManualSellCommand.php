<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class ManualSellCommand extends Command
{
    protected $signature = 'trade:sell
        {symbol : Trading pair (e.g. BTCUSDT)}
        {quantity? : Quantity to sell (e.g. 0.001). Ignored if --all is used}
        {--bot= : Bot ID (optional, uses first active bot if not specified)}
        {--dry-run : Test mode (no real trading)}
        {--all : Sell all available balance}';

    protected $description = 'Ручная продажа (Manual SELL order)';

    public function handle(): int
    {
        $symbol = strtoupper($this->argument('symbol'));
        $botId = $this->option('bot');
        $dryRun = $this->option('dry-run');
        $sellAll = $this->option('all');

        // Получаем бота
        if ($botId) {
            $bot = TradingBot::with('exchangeAccount')->find($botId);
            if (!$bot) {
                $this->error("Бот #{$botId} не найден (Bot #{$botId} not found)");
                return self::FAILURE;
            }
        } else {
            $bot = TradingBot::with('exchangeAccount')->where('is_active', true)->first();
            if (!$bot) {
                $this->error('Активных ботов не найдено (No active bots found)');
                return self::FAILURE;
            }
        }

        if (!$bot->exchangeAccount) {
            $this->error('Аккаунт биржи не привязан (No exchange account attached)');
            return self::FAILURE;
        }

        $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
        $telegram = new TelegramService();

        // Получаем текущую цену
        try {
            $price = $exchangeService->getPrice($symbol);
            $this->info("Текущая цена (Current price): {$price}");
        } catch (\Throwable $e) {
            $this->error('Ошибка получения цены (Price error): ' . $e->getMessage());
            return self::FAILURE;
        }

        // Определяем количество для продажи
        $baseCoin = str_replace('USDT', '', $symbol);
        if ($sellAll) {
            // Продаем весь доступный баланс
            try {
                $quantity = $exchangeService->getBalance($baseCoin);
                $this->info("Доступный баланс {$baseCoin} (Available {$baseCoin} balance): {$quantity}");
            } catch (\Throwable $e) {
                $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                return self::FAILURE;
            }
        } else {
            // Продаем указанное количество
            $quantityArg = $this->argument('quantity');
            if ($quantityArg === null) {
                $this->error("Необходимо указать количество или использовать --all (Quantity required or use --all flag)");
                $this->info("Примеры (Examples):");
                $this->line("  php artisan trade:sell BTCUSDT 0.001");
                $this->line("  php artisan trade:sell BTCUSDT --all");
                return self::FAILURE;
            }
            $quantity = (float) $quantityArg;
        }

        if ($quantity <= 0) {
            $this->error("Количество должно быть больше 0 (Quantity must be greater than 0)");
            return self::FAILURE;
        }

        // Округляем количество в зависимости от монеты и режима
        if ($sellAll) {
            // При --all используем точное значение баланса (не округляем вверх)
            // Округляем вниз до 8 знаков для безопасности
            $quantity = floor($quantity * 100000000) / 100000000;
        } else {
            // Для конкретного количества округляем до 4 знаков (для BTC)
            $quantity = round($quantity, 4);
        }

        // Проверка минимального размера ордера для OKX
        $exchange = $bot->exchangeAccount->exchange;
        if ($exchange === 'okx') {
            // Минимальные размеры ордеров для разных монет на OKX
            // Примечание: реальные минимумы могут отличаться, но это приблизительные значения
            $minQuantities = [
                'BTC' => 0.0001,
                'ETH' => 0.001,
                'SOL' => 0.01,  // Может быть меньше, но для безопасности используем 0.01
                'BNB' => 0.001,
                'ADA' => 1.0,
                'DOGE' => 10.0,
                'XRP' => 1.0,
            ];
            
            // Для SOL минимальный размер может быть меньше (0.001), но проверим
            // Если баланс очень мал, но больше 0.001, попробуем продать
            if ($baseCoin === 'SOL' && $quantity >= 0.001 && $quantity < 0.01) {
                // Для SOL с балансом от 0.001 до 0.01 попробуем продать (может сработать)
                $this->warn("⚠️  Баланс SOL ({$quantity}) меньше рекомендуемого минимума (0.01), но попробуем продать...");
                // Продолжаем выполнение без проверки минимума
            } else {
                $minQuantity = $minQuantities[$baseCoin] ?? 0.0001; // По умолчанию для BTC
                
                if ($quantity < $minQuantity) {
                    $this->error("Количество {$quantity} {$baseCoin} меньше минимума OKX ({$minQuantity} {$baseCoin}) (Quantity {$quantity} {$baseCoin} is less than OKX minimum ({$minQuantity} {$baseCoin}))");
                    $this->warn("Минимальный размер ордера для {$symbol} на OKX: {$minQuantity} {$baseCoin}");
                    $this->warn("Текущий баланс: {$quantity} {$baseCoin}");
                    
                    if ($sellAll) {
                        $this->warn("⚠️  Ваш баланс слишком мал для продажи на OKX. Минимальный размер ордера: {$minQuantity} {$baseCoin}");
                    }
                    
                    return self::FAILURE;
                }
            }
        }

        $this->info("Ручная продажа (Manual SELL order)");
        $this->line("Бот (Bot): #{$bot->id}");
        $this->line("Символ (Symbol): {$symbol}");
        $this->line("Количество (Quantity): {$quantity} {$baseCoin}");
        $this->line("Режим (Mode): " . ($dryRun ? 'ТЕСТОВЫЙ (DRY RUN)' : 'РЕАЛЬНАЯ ТОРГОВЛЯ (REAL TRADING)'));
        $this->line('');

        // Проверка баланса (если реальная торговля)
        if (!$dryRun) {
            try {
                $availableBalance = $exchangeService->getBalance($baseCoin);
                $this->line("Доступный баланс {$baseCoin} (Available {$baseCoin} balance): {$availableBalance}");

                if ($availableBalance < $quantity) {
                    $this->error("Недостаточно баланса. Требуется: {$quantity} {$baseCoin}, Доступно: {$availableBalance} {$baseCoin} (Insufficient balance. Required: {$quantity} {$baseCoin}, Available: {$availableBalance} {$baseCoin})");
                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // Найти открытый BUY для parent_id (FIFO)
        $buyTrade = Trade::where('trading_bot_id', $bot->id)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->first();

        // Тестовый режим
        if ($dryRun) {
            $this->warn("ТЕСТОВЫЙ РЕЖИМ SELL (DRY RUN SELL) {$quantity} {$baseCoin}");
            $telegram->notifySell($symbol, $quantity, $price, true);
            $this->info('Тестовая продажа выполнена (Test sell executed)');
            return self::SUCCESS;
        }

        // Реальная продажа
        $this->warn("РЕАЛЬНАЯ ПРОДАЖА ВЫПОЛНЯЕТСЯ (REAL SELL EXECUTING) ({$quantity} {$baseCoin})");
        $telegram->notifySell($symbol, $quantity, $price, false);

        // Создаем SELL ордер в БД
        $sell = Trade::create([
            'trading_bot_id' => $bot->id,
            'parent_id'      => $buyTrade->id ?? null,
            'side'           => 'SELL',
            'symbol'         => $symbol,
            'price'          => 0, // обновится после FILLED
            'quantity'       => $quantity,
            'status'         => 'PENDING',
        ]);

        try {
            $response = $exchangeService->placeMarketSellBtc(
                symbol: $symbol,
                btcQty: $quantity
            );

            $exchange = $bot->exchangeAccount->exchange;
            $orderId = null;
            if ($exchange === 'bybit') {
                $orderId = $response['result']['orderId'] ?? null;
            } elseif ($exchange === 'okx') {
                $orderId = $response['data'][0]['ordId'] ?? null;
            }

            $sell->update([
                'order_id'          => $orderId,
                'status'            => $orderId ? 'SENT' : 'FAILED',
                'exchange_response' => $response,
            ]);

            if (!$orderId) {
                $this->error("{$exchange} не вернул orderId ({$exchange} did not return orderId)");
                return self::FAILURE;
            }

            // Даём бирже исполнить market-ордер
            usleep(500_000);

            // Проверяем статус ордера
            $orderResponse = $exchangeService->getOrder($symbol, $orderId);
            $order = null;
            if ($exchange === 'bybit') {
                $order = $orderResponse['result']['list'][0] ?? null;
            } elseif ($exchange === 'okx') {
                $order = $orderResponse['data'][0] ?? null;
            }

            if ($order) {
                $isFilled = false;
                $fee = 0;
                $feeCurrency = null;

                if ($exchange === 'bybit') {
                    $isFilled = ($order['orderStatus'] ?? '') === 'Filled';
                    $fee = (float) ($order['cumExecFee'] ?? 0);
                    $feeCurrency = $order['feeCurrency'] ?? null;
                } elseif ($exchange === 'okx') {
                    $isFilled = ($order['state'] ?? '') === 'filled';
                    $fee = (float) ($order['fee'] ?? 0);
                    $feeCurrency = $order['feeCcy'] ?? null;
                }

                if ($isFilled) {
                    $execPrice = (float) ($order['avgPrice'] ?? $order['avgPx'] ?? $price);
                    $sell->update([
                        'price'       => $execPrice,
                        'fee'         => $fee,
                        'fee_currency' => $feeCurrency,
                        'status'      => 'FILLED',
                        'filled_at'   => now(),
                    ]);

                    $this->info('ОРДЕР SELL ИСПОЛНЕН (SELL ORDER FILLED)');
                    $telegram->notifyFilled('SELL', $symbol, $quantity, $execPrice, $fee);
                    $this->line("Цена исполнения (Execution price): {$execPrice} USDT");
                    $this->line("Комиссия (Fee): {$fee} {$feeCurrency}");
                    $this->info('Запустите php artisan orders:sync для расчета PnL и закрытия позиции');
                } else {
                    $this->warn('Ордер отправлен, но еще не исполнен (Order sent but not filled yet)');
                    $this->info("Order ID: {$orderId}");
                    $this->info('Запустите php artisan orders:sync для проверки статуса');
                }
            } else {
                $this->warn('Ордер еще не найден (Order not found yet)');
                $this->info("Order ID: {$orderId}");
                $this->info('Запустите php artisan orders:sync для проверки статуса');
            }

        } catch (\Throwable $e) {
            $telegram->notifyError('Ручная продажа (Manual SELL)', $e->getMessage());
            $sell->update([
                'status'            => 'FAILED',
                'exchange_response' => ['error' => $e->getMessage()],
            ]);

            $this->error('Ошибка продажи (Sell error): ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
