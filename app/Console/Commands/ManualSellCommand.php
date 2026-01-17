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
        {quantity : Quantity to sell (e.g. 0.001)}
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
            try {
                $quantity = $exchangeService->getBalance($baseCoin);
                $this->info("Доступный баланс {$baseCoin} (Available {$baseCoin} balance): {$quantity}");
            } catch (\Throwable $e) {
                $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                return self::FAILURE;
            }
        } else {
            $quantity = (float) $this->argument('quantity');
        }

        if ($quantity <= 0) {
            $this->error("Количество должно быть больше 0 (Quantity must be greater than 0)");
            return self::FAILURE;
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
