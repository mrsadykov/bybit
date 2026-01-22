<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class ManualBuyCommand extends Command
{
    protected $signature = 'trade:buy
        {amount : Amount in USDT to buy}
        {symbol? : Trading pair (e.g. BTCUSDT). Optional if --bot is specified}
        {--bot= : Bot ID (optional, uses first active bot if not specified)}
        {--dry-run : Test mode (no real trading)}';

    protected $description = 'Ручная покупка (Manual BUY order)';

    public function handle(): int
    {
        $amount = (float) $this->argument('amount');
        $symbolArg = $this->argument('symbol');
        $botId = $this->option('bot');
        $dryRun = $this->option('dry-run');

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

        // Определяем символ: из аргумента или из бота
        if ($symbolArg) {
            $symbol = strtoupper($symbolArg);
            // Проверяем, что символ совпадает с символом бота (если указан бот)
            if ($botId && $bot->symbol !== $symbol) {
                $this->warn("⚠️  Внимание: Указанный символ ({$symbol}) не совпадает с символом бота #{$bot->id} ({$bot->symbol})");
                $this->warn("Будет использован указанный символ: {$symbol}");
            }
        } else {
            // Используем символ из бота
            $symbol = $bot->symbol;
            $this->info("Используется символ бота (Using bot symbol): {$symbol}");
        }

        $this->info("Ручная покупка (Manual BUY order)");
        $this->line("Бот (Bot): #{$bot->id}");
        $this->line("Символ (Symbol): {$symbol}");
        $this->line("Сумма (Amount): {$amount} USDT");
        $this->line("Режим (Mode): " . ($dryRun ? 'ТЕСТОВЫЙ (DRY RUN)' : 'РЕАЛЬНАЯ ТОРГОВЛЯ (REAL TRADING)'));
        $this->line('');

        // Проверка минимальной суммы
        $minNotional = config('trading.min_notional_usdt', 1);
        if ($amount < $minNotional) {
            $this->error("Сумма {$amount} USDT меньше минимума {$minNotional} USDT (Amount {$amount} USDT is less than minimum {$minNotional} USDT)");
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

        // Проверка баланса (если реальная торговля)
        if (!$dryRun) {
            try {
                $balance = $exchangeService->getBalance('USDT');
                $this->line("Баланс USDT (USDT Balance): {$balance}");

                if ($balance < $amount) {
                    $this->error("Недостаточно баланса. Требуется: {$amount} USDT, Доступно: {$balance} USDT (Insufficient balance. Required: {$amount} USDT, Available: {$balance} USDT)");
                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // Тестовый режим
        if ($dryRun) {
            $this->warn("ТЕСТОВЫЙ РЕЖИМ BUY (DRY RUN BUY) {$amount} USDT");
            $telegram->notifyBuy($symbol, $amount, $price, true);
            $this->info('Тестовая покупка выполнена (Test buy executed)');
            return self::SUCCESS;
        }

        // Реальная покупка
        $this->warn("РЕАЛЬНАЯ ПОКУПКА ВЫПОЛНЯЕТСЯ (REAL BUY EXECUTING) ({$amount} USDT)");
        $telegram->notifyBuy($symbol, $amount, $price, false);

        // Создаем запись о сделке в БД
        $trade = $bot->trades()->create([
            'side'     => 'BUY',
            'symbol'   => $symbol,
            'price'    => $price,
            'quantity' => 0, // узнаем после FILLED
            'status'   => 'PENDING',
        ]);

        try {
            $response = $exchangeService->placeMarketBuy($symbol, $amount);
            $exchange = $bot->exchangeAccount->exchange;

            // Обрабатываем разные форматы ответов
            $orderId = null;
            if ($exchange === 'bybit') {
                if (($response['retCode'] ?? 1) !== 0) {
                    $trade->update([
                        'status' => 'FAILED',
                        'exchange_response' => $response,
                    ]);
                    $this->error('Ошибка Bybit (Bybit error): ' . json_encode($response));
                    return self::FAILURE;
                }
                $orderId = $response['result']['orderId'] ?? null;
            } elseif ($exchange === 'okx') {
                if (($response['code'] ?? '0') !== '0') {
                    $trade->update([
                        'status' => 'FAILED',
                        'exchange_response' => $response,
                    ]);
                    $this->error('Ошибка OKX (OKX error): ' . json_encode($response));
                    return self::FAILURE;
                }
                $orderId = $response['data'][0]['ordId'] ?? null;
            }

            if (!$orderId) {
                $trade->update(['status' => 'FAILED']);
                $this->error("{$exchange} не вернул orderId ({$exchange} did not return orderId)");
                return self::FAILURE;
            }

            $trade->update([
                'order_id' => $orderId,
                'status'   => 'SENT',
            ]);

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
                $quantity = 0;
                $fee = 0;
                $feeCurrency = null;

                if ($exchange === 'bybit') {
                    $isFilled = ($order['orderStatus'] ?? '') === 'Filled';
                    $quantity = (float) ($order['cumExecQty'] ?? 0);
                    $fee = (float) ($order['cumExecFee'] ?? 0);
                    $feeCurrency = $order['feeCurrency'] ?? null;
                } elseif ($exchange === 'okx') {
                    $isFilled = ($order['state'] ?? '') === 'filled';
                    $quantity = (float) ($order['accFillSz'] ?? 0);
                    $fee = (float) ($order['fee'] ?? 0);
                    $feeCurrency = $order['feeCcy'] ?? null;
                }

                if ($isFilled) {
                    $trade->update([
                        'quantity'     => $quantity,
                        'fee'          => $fee,
                        'fee_currency' => $feeCurrency,
                        'status'       => 'FILLED',
                        'filled_at'    => now(),
                    ]);

                    $this->info('ОРДЕР BUY ИСПОЛНЕН (BUY ORDER FILLED)');
                    $telegram->notifyFilled('BUY', $symbol, $quantity, $price, $fee);
                    $this->line("Количество (Quantity): {$quantity} BTC");
                    $this->line("Комиссия (Fee): {$fee} {$feeCurrency}");
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
            $telegram->notifyError('Ручная покупка (Manual BUY)', $e->getMessage());
            $trade->update([
                'status'            => 'FAILED',
                'exchange_response' => ['error' => $e->getMessage()],
            ]);

            $this->error('Ошибка покупки (Buy error): ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
