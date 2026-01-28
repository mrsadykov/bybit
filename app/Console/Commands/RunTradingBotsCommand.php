<?php

namespace App\Console\Commands;

use App\Models\Trade;
use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\Trading\PositionManager;
use App\Services\TelegramService;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Indicators\EmaIndicator;
use App\Trading\Strategies\RsiEmaStrategy;

class RunTradingBotsCommand extends Command
{
    protected $signature = 'bots:run';
    protected $description = 'Run trading bots';

    public function handle(): int
    {
        $this->info('Запуск торговых ботов (Starting trading bots)...');

        $bots = TradingBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get();

        if ($bots->isEmpty()) {
            $this->warn('Активных ботов не найдено (No active bots found)');
            return self::SUCCESS;
        }

        // Уведомление о запуске команды
        $telegram = new TelegramService();
        $telegram->notifyBotRunStart($bots->count());

        foreach ($bots as $bot) {

            $this->line(str_repeat('-', 30));
            $this->info("Бот #{$bot->id} (Bot #{$bot->id})");
            $this->line("Символ (Symbol): {$bot->symbol}");
            $this->line("Размер позиции (Position size) (USDT): {$bot->position_size}");

            if (! $bot->exchangeAccount) {
                $this->error('Аккаунт биржи не привязан (No exchange account attached)');
                continue;
            }

            $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
            $positionManager = new PositionManager($bot);
            $telegram = new TelegramService();

            /*
            |--------------------------------------------------------------------------
            | PRICE
            |--------------------------------------------------------------------------
            */
            try {
                $price = $exchangeService->getPrice($bot->symbol);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения цены (Price error): ' . $e->getMessage());
                continue;
            }

            $this->line("Текущая цена (Current price): {$price}");

            /*
            |--------------------------------------------------------------------------
            | CANDLES
            |--------------------------------------------------------------------------
            */
            try {
                $candles = $exchangeService->getCandles($bot->symbol, $bot->timeframe, 100);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения свечей (Candles error): ' . $e->getMessage());
                continue;
            }

            // Обрабатываем разные форматы ответов (Bybit vs OKX)
            $candleList = [];
            $exchange = $bot->exchangeAccount->exchange;
            
            if ($exchange === 'bybit') {
                $candleList = $candles['result']['list'] ?? [];
            } elseif ($exchange === 'okx') {
                $candleList = $candles['data'] ?? [];
            }

            if (empty($candleList) || count($candleList) < 20) {
                $this->warn('Недостаточно данных свечей (Not enough candle data)');
                continue;
            }

            $closes = array_map(
                fn ($candle) => (float) $candle[4],
                array_reverse($candleList)
            );

            /*
            |--------------------------------------------------------------------------
            | INDICATORS
            |--------------------------------------------------------------------------
            */
            // Получаем периоды из БД или используем значения по умолчанию
            $rsiPeriod = $bot->rsi_period ?? 17;
            $emaPeriod = $bot->ema_period ?? 10;

            $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
            $ema = EmaIndicator::calculate($closes, $emaPeriod);

            $this->line('RSI (' . $rsiPeriod . '): ' . round($rsi, 2));
            $this->line('EMA (' . $emaPeriod . '): ' . round($ema, 2));

            /*
            |--------------------------------------------------------------------------
            | STRATEGY
            |--------------------------------------------------------------------------
            */
            /*
            |--------------------------------------------------------------------------
            | POSITION STATE (определяем ДО использования в логах)
            |--------------------------------------------------------------------------
            */
            $netPosition = $positionManager->getNetPosition();
            $this->line('Чистая позиция (Net position) (BTC): ' . $netPosition);
            
            // Сохраняем значения для возможного использования в HOLD
            $lastRsi = is_array($rsi) ? end($rsi) : $rsi;
            $lastEma = is_array($ema) ? end($ema) : $ema;

            /*
            |--------------------------------------------------------------------------
            | STRATEGY
            |--------------------------------------------------------------------------
            */
            $signal = RsiEmaStrategy::decide($closes, $rsiPeriod, $emaPeriod);
            $this->info("Сигнал (Signal): {$signal}");

            // Детальное логирование решения стратегии
            logger()->info('Trading bot decision', [
                'bot_id' => $bot->id,
                'symbol' => $bot->symbol,
                'signal' => $signal,
                'price' => $price,
                'rsi' => round($lastRsi, 2),
                'rsi_period' => $rsiPeriod,
                'ema' => round($lastEma, 2),
                'ema_period' => $emaPeriod,
                'net_position' => $netPosition,
                'can_buy' => $positionManager->canBuy(),
                'can_sell' => $positionManager->canSell(),
                'timeframe' => $bot->timeframe,
                'candles_count' => count($closes),
                'decision_reason' => $this->getDecisionReason($signal, $lastRsi, $lastEma, $price, $netPosition),
            ]);

            /*
            |--------------------------------------------------------------------------
            | STOP-LOSS / TAKE-PROFIT CHECK
            |--------------------------------------------------------------------------
            */
            $actionTaken = false; // Флаг для отслеживания выполненных действий
            
            if ($netPosition > 0 && ($bot->stop_loss_percent || $bot->take_profit_percent)) {
                // Получаем все открытые BUY позиции
                $openBuys = Trade::where('trading_bot_id', $bot->id)
                    ->where('side', 'BUY')
                    ->where('status', 'FILLED')
                    ->whereNull('closed_at')
                    ->get();

                foreach ($openBuys as $buyTrade) {
                    $buyPrice = (float) $buyTrade->price;
                    $priceChange = (($price - $buyPrice) / $buyPrice) * 100;

                    $shouldSell = false;
                    $reason = '';

                    // Проверка Stop-Loss
                    if ($bot->stop_loss_percent && $priceChange <= -abs($bot->stop_loss_percent)) {
                        $shouldSell = true;
                        $reason = "STOP-LOSS ({$bot->stop_loss_percent}%)";
                        $this->warn("🔴 STOP-LOSS сработал! ({$reason}) - Цена упала на " . number_format(abs($priceChange), 2) . "%");
                    }

                    // Проверка Take-Profit
                    if ($bot->take_profit_percent && $priceChange >= $bot->take_profit_percent) {
                        $shouldSell = true;
                        $reason = "TAKE-PROFIT ({$bot->take_profit_percent}%)";
                        $this->warn("🟢 TAKE-PROFIT сработал! ({$reason}) - Цена выросла на " . number_format($priceChange, 2) . "%");
                    }

                    if ($shouldSell) {
                        // Получаем реальный баланс для продажи
                        try {
                            $baseCoin = str_replace('USDT', '', $bot->symbol);
                            $btcQty = $exchangeService->getBalance($baseCoin);
                            
                            if ($btcQty > 0) {
                                // Проверка минимального размера ордера для SELL (SL/TP)
                                [$passesMin, $minQty] = $positionManager->passesMinSell($bot->symbol, $btcQty);
                                if (!$passesMin) {
                                    $this->warn("Количество {$btcQty} {$baseCoin} меньше минимума {$minQty} {$baseCoin} — пропуск SELL ({$reason}) (Quantity {$btcQty} {$baseCoin} is less than minimum {$minQty} {$baseCoin} — skip SELL ({$reason}))");
                                    $telegram->notifySkip('SELL', "Quantity too small for {$reason}: {$btcQty} < {$minQty}");
                                    continue;
                                }

                                // Проверка dry_run
                                if (!config('trading.real_trading') || $bot->dry_run) {
                                    $this->warn("ТЕСТОВЫЙ РЕЖИМ SELL ({$reason}) (DRY RUN SELL) {$btcQty} {$baseCoin}");
                                    $telegram->notifySell($bot->symbol, $btcQty, $price, true);
                                } else {
                                    $this->warn("РЕАЛЬНАЯ ПРОДАЖА ({$reason}) ВЫПОЛНЯЕТСЯ (REAL SELL EXECUTING) ({$btcQty} {$baseCoin})");
                                    $telegram->notifySell($bot->symbol, $btcQty, $price, false);

                                    // Создаем SELL ордер
                                    $sell = Trade::create([
                                        'trading_bot_id' => $bot->id,
                                        'parent_id' => $buyTrade->id,
                                        'side' => 'SELL',
                                        'symbol' => $bot->symbol,
                                        'price' => 0,
                                        'quantity' => $btcQty,
                                        'status' => 'PENDING',
                                    ]);

                                    try {
                                        $response = $exchangeService->placeMarketSellBtc(
                                            symbol: $bot->symbol,
                                            btcQty: $btcQty
                                        );

                                        $exchange = $bot->exchangeAccount->exchange;
                                        $orderId = null;
                                        if ($exchange === 'bybit') {
                                            $orderId = $response['result']['orderId'] ?? null;
                                        } elseif ($exchange === 'okx') {
                                            $orderId = $response['data'][0]['ordId'] ?? null;
                                        }

                                        $sell->update([
                                            'order_id' => $orderId,
                                            'status' => $orderId ? 'SENT' : 'FAILED',
                                            'exchange_response' => $response,
                                        ]);

                                        logger()->info("SELL order ({$reason}) initiated", [
                                            'bot_id' => $bot->id,
                                            'buy_trade_id' => $buyTrade->id,
                                            'sell_trade_id' => $sell->id,
                                            'reason' => $reason,
                                            'price_change' => $priceChange,
                                        ]);
                                        
                                        $actionTaken = true; // Действие выполнено
                                    } catch (\Throwable $e) {
                                        $telegram->notifyError("SELL ({$reason})", $e->getMessage());
                                        $sell->update([
                                            'status' => 'FAILED',
                                            'exchange_response' => ['error' => $e->getMessage()],
                                        ]);
                                        $this->error("SELL ({$reason}) exception: " . $e->getMessage());
                                        $actionTaken = true; // Действие было попыткой, даже если не удалось
                                    }
                                }
                                break; // Закрываем только одну позицию за раз
                            }
                        } catch (\Throwable $e) {
                            $this->error("Ошибка проверки баланса для {$reason}: " . $e->getMessage());
                            logger()->error("Balance check error for {$reason}", [
                                'bot_id' => $bot->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | BUY
            |--------------------------------------------------------------------------
            */
            if ($signal === 'BUY') {

                if (! $positionManager->canBuy()) {
                    $this->warn('BUY пропущен: позиция уже открыта (BUY skipped: position already open)');
                    $telegram->notifySkip('BUY', 'Position already open');
                    continue;
                }

                $usdtAmount = (float) $bot->position_size;

                if ($usdtAmount <= 0) {
                    $this->warn('Неверный размер позиции (Invalid position size)');
                    $telegram->notifySkip('BUY', 'Invalid position size');
                    continue;
                }

                // Проверка минимальной суммы
                $minNotional = config('trading.min_notional_usdt', 1);
                if ($usdtAmount < $minNotional) {
                    $this->warn("BUY пропущен: сумма {$usdtAmount} USDT меньше минимума {$minNotional} USDT (BUY skipped: amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT)");
                    $telegram->notifySkip('BUY', "Amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT");
                    continue;
                }

                // Проверка баланса перед покупкой
                if (config('trading.real_trading') && ! $bot->dry_run) {
                    try {
                        $balance = $exchangeService->getBalance('USDT');
                        $this->line("Баланс USDT (USDT Balance): {$balance}");

                        if ($balance < $usdtAmount) {
                            $this->error("BUY пропущен: недостаточно баланса. Требуется: {$usdtAmount} USDT, Доступно: {$balance} USDT (BUY skipped: insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT)");
                            logger()->warning('Insufficient balance for BUY', [
                                'bot_id' => $bot->id,
                                'required' => $usdtAmount,
                                'available' => $balance,
                            ]);
                            $telegram->notifySkip('BUY', "Insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT");
                            continue;
                        }
                    } catch (\Throwable $e) {
                        $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                        logger()->error('Balance check error', [
                            'bot_id' => $bot->id,
                            'error' => $e->getMessage(),
                        ]);
                        $telegram->notifyError('BUY Balance Check', $e->getMessage());
                        continue;
                    }
                }

                if (! config('trading.real_trading') || $bot->dry_run) {
                    $this->warn("ТЕСТОВЫЙ РЕЖИМ BUY (DRY RUN BUY) {$usdtAmount} USDT");
                    $telegram->notifyBuy($bot->symbol, $usdtAmount, $price, true);
                    continue;
                }

                $this->warn("РЕАЛЬНАЯ ПОКУПКА ВЫПОЛНЯЕТСЯ (REAL BUY EXECUTING) ({$usdtAmount} USDT)");

                // Уведомление в Telegram
                $telegram->notifyBuy($bot->symbol, $usdtAmount, $price, false);

                // Логирование начала сделки
                logger()->info('BUY order initiated', [
                    'bot_id' => $bot->id,
                    'symbol' => $bot->symbol,
                    'amount_usdt' => $usdtAmount,
                    'price' => $price,
                ]);

                $trade = $bot->trades()->create([
                    'side'     => 'BUY',
                    'symbol'   => $bot->symbol,
                    'price'    => $price,   // цена на момент отправки
                    'quantity' => 0,        // узнаем после FILLED
                    'status'   => 'PENDING',
                ]);

                try {
                    $response = $exchangeService->placeMarketBuy(
                        $bot->symbol,
                        $usdtAmount
                    );

                    $exchange = $bot->exchangeAccount->exchange;
                    
                    // Обрабатываем разные форматы ответов
                    if ($exchange === 'bybit') {
                    if (($response['retCode'] ?? 1) !== 0) {
                        $trade->update([
                            'status' => 'FAILED',
                            'exchange_response' => $response,
                        ]);
                            $this->error('Ошибка Bybit (Bybit error): ' . json_encode($response));
                            continue;
                        }
                        $orderId = $response['result']['orderId'] ?? null;
                    } elseif ($exchange === 'okx') {
                        // OKX уже проверяет code в privateRequest, но на всякий случай
                        if (($response['code'] ?? '0') !== '0') {
                            $trade->update([
                                'status' => 'FAILED',
                                'exchange_response' => $response,
                            ]);
                            $this->error('Ошибка OKX (OKX error): ' . json_encode($response));
                            continue;
                        }
                        $orderId = $response['data'][0]['ordId'] ?? null;
                    } else {
                        $this->error('Неподдерживаемая биржа (Unsupported exchange): ' . $exchange);
                        continue;
                    }

                    if (! $orderId) {
                        $trade->update([
                            'status' => 'FAILED',
                        ]);
                        $this->error("{$exchange} не вернул orderId ({$exchange} did not return orderId)");
                        continue;
                    }

                    // сохраняем order_id
                    $trade->update([
                        'order_id' => $orderId,
                        'status'   => 'SENT',
                    ]);

                    // даём бирже исполнить market-ордер
                    usleep(500_000);

                    // 9.4 — проверяем статус
                    $orderResponse = $exchangeService->getOrder(
                        $bot->symbol,
                        $orderId
                    );

                    // Обрабатываем разные форматы ответов
                    $order = null;
                    if ($exchange === 'bybit') {
                    $order = $orderResponse['result']['list'][0] ?? null;
                    } elseif ($exchange === 'okx') {
                        $order = $orderResponse['data'][0] ?? null;
                    }

                    if (! $order) {
                        $this->warn('Ордер еще не найден (Order not found yet)');
                        continue;
                    }

                    // Обрабатываем статус ордера
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
                        
                        // Уведомление в Telegram
                        $telegram->notifyFilled('BUY', $bot->symbol, $quantity, $trade->price, $fee);
                        
                        logger()->info('BUY order filled', [
                            'bot_id' => $bot->id,
                            'trade_id' => $trade->id,
                            'order_id' => $orderId,
                            'quantity' => $trade->quantity,
                            'price' => $trade->price,
                            'fee' => $trade->fee,
                        ]);
                    } else {
                        $orderStatus = $exchange === 'bybit' 
                            ? ($order['orderStatus'] ?? 'Unknown')
                            : ($order['state'] ?? 'Unknown');
                        
                        $trade->update([
                            'status' => 'SENT',
                        ]);

                        $this->info('ОРДЕР BUY ОТПРАВЛЕН (BUY ORDER SENT)');
                        $this->warn('Статус ордера (Order status): ' . $orderStatus);
                        
                        logger()->info('BUY order sent (not filled yet)', [
                            'bot_id' => $bot->id,
                            'trade_id' => $trade->id,
                            'order_id' => $orderId,
                            'status' => $orderStatus,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $telegram->notifyError('BUY Order', $e->getMessage());
                    
                    $trade->update([
                        'status' => 'FAILED',
                        'exchange_response' => $e->getMessage(),
                    ]);

                    $this->error('Исключение BUY (BUY exception): ' . $e->getMessage());
                    continue;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | SELL (будет аккуратно добавлен позже)
            |--------------------------------------------------------------------------
            */
            if ($signal === 'SELL') {
                //$this->warn('SELL logic not implemented yet');
                //continue;

                // Найти первый открытый BUY для parent_id (для связи)
                $buy = Trade::where('trading_bot_id', $bot->id)
                    ->where('side', 'BUY')
                    ->where('status', 'FILLED')
                    ->whereNull('closed_at')
                    ->first();

                if (! $buy) {
                    $this->line('Нет открытой BUY позиции — пропуск SELL (No open BUY position — skip SELL)');
                    $telegram->notifySkip('SELL', 'No open BUY position');
                    continue;
                }

                // Защита от двойного SELL
                $hasPendingSell = Trade::where('trading_bot_id', $bot->id)
                    ->where('side', 'SELL')
                    ->whereIn('status', ['PENDING', 'SENT'])
                    ->whereNull('closed_at')
                    ->exists();

                if ($hasPendingSell) {
                    $this->line('SELL уже выполняется — пропуск (SELL already in progress — skip)');
                    $telegram->notifySkip('SELL', 'SELL already in progress');
                    continue;
                }

                // Получаем реальный баланс BTC с биржи (более точный)
                try {
                    $baseCoin = str_replace('USDT', '', $bot->symbol);
                    $btcQty = $exchangeService->getBalance($baseCoin);
                    $this->line("Доступный баланс {$baseCoin} (Available {$baseCoin} balance): {$btcQty}");
                } catch (\Throwable $e) {
                    $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                    $telegram->notifyError('SELL Balance Check', $e->getMessage());
                    // Fallback: используем netPosition из БД
                    $btcQty = $positionManager->getNetPosition();
                    $this->warn("Используем чистую позицию из БД (Using net position from DB): {$btcQty}");
                }

                if ($btcQty <= 0) {
                    $this->line('Баланс недоступен — пропуск SELL (No balance available — skip SELL)');
                    $telegram->notifySkip('SELL', 'No balance available');
                    continue;
                }

                // Проверка минимального размера ордера для SELL
                [$passesMin, $minQty] = $positionManager->passesMinSell($bot->symbol, $btcQty);
                if (!$passesMin) {
                    $this->warn("Количество {$btcQty} {$baseCoin} меньше минимума {$minQty} {$baseCoin} — пропуск SELL (Quantity {$btcQty} {$baseCoin} is less than minimum {$minQty} {$baseCoin} — skip SELL)");
                    $telegram->notifySkip('SELL', "Quantity too small: {$btcQty} < {$minQty}");
                    continue;
                }

                // Проверка dry_run для SELL
                if (! config('trading.real_trading') || $bot->dry_run) {
                    $this->warn("ТЕСТОВЫЙ РЕЖИМ SELL (DRY RUN SELL) {$btcQty} {$baseCoin}");
                    $telegram->notifySell($bot->symbol, $btcQty, $price, true);
                    continue;
                }

                $this->warn("РЕАЛЬНАЯ ПРОДАЖА ВЫПОЛНЯЕТСЯ (REAL SELL EXECUTING) ({$btcQty} {$baseCoin})");

                // Уведомление в Telegram
                $telegram->notifySell($bot->symbol, $btcQty, $price, false);

                // Логирование начала продажи
                logger()->info('SELL order initiated', [
                    'bot_id' => $bot->id,
                    'symbol' => $bot->symbol,
                    'quantity' => $btcQty,
                    'buy_trade_id' => $buy->id,
                ]);

                // Создаём SELL в БД (ДО API) с реальным балансом
                $sell = Trade::create([
                    'trading_bot_id' => $bot->id,
                    'parent_id'      => $buy->id,
                    'side'           => 'SELL',
                    'symbol'         => $buy->symbol,
                    'price'          => 0, // обновится после FILLED
                    'quantity'       => $btcQty,
                    'status'         => 'PENDING',
                ]);

                try {
                    $exchange = $bot->exchangeAccount->exchange;

                    $response = $exchangeService->placeMarketSellBtc(
                        symbol: $buy->symbol,
                        btcQty: $btcQty
                    );

                    // Обрабатываем разные форматы ответов
                    $orderId = null;
                    if ($exchange === 'bybit') {
                        $orderId = $response['result']['orderId'] ?? null;
                    } elseif ($exchange === 'okx') {
                        $orderId = $response['data'][0]['ordId'] ?? null;
                    }

                    if (! $orderId) {
                        $sell->update([
                            'status'            => 'FAILED',
                            'exchange_response' => $response,
                        ]);
                        $this->error("{$exchange} не вернул orderId для SELL ({$exchange} did not return orderId for SELL)");
                        continue;
                    }

                    $sell->update([
                        'order_id'          => $orderId,
                        'status'            => 'SENT',
                        'exchange_response' => $response,
                    ]);

                    // даём бирже обработать ордер
                    usleep(500_000);

                    // Проверка статуса ордера (как в BUY)
                    try {
                        $orderResponse = $exchangeService->getOrder(
                            $bot->symbol,
                            $orderId
                        );

                        // Обрабатываем разные форматы ответов
                        $order = null;
                        if ($exchange === 'bybit') {
                            $order = $orderResponse['result']['list'][0] ?? null;
                        } elseif ($exchange === 'okx') {
                            $order = $orderResponse['data'][0] ?? null;
                        }

                        if (! $order) {
                            $this->warn('SELL ордер еще не найден (SELL order not found yet)');
                            continue;
                        }

                        // Обрабатываем статус ордера
                        $isFilled = false;
                        $quantity = 0;
                        $fee = 0;
                        $feeCurrency = null;
                        $filledPrice = 0;
                        
                        if ($exchange === 'bybit') {
                            $isFilled = ($order['orderStatus'] ?? '') === 'Filled';
                            $quantity = (float) ($order['cumExecQty'] ?? 0);
                            $fee = (float) ($order['cumExecFee'] ?? 0);
                            $feeCurrency = $order['feeCurrency'] ?? null;
                            $filledPrice = (float) ($order['avgPrice'] ?? $price);
                        } elseif ($exchange === 'okx') {
                            $isFilled = ($order['state'] ?? '') === 'filled';
                            $quantity = (float) ($order['accFillSz'] ?? 0);
                            $fee = (float) ($order['fee'] ?? 0);
                            $feeCurrency = $order['feeCcy'] ?? null;
                            $filledPrice = (float) ($order['avgPx'] ?? $price);
                        }

                        if ($isFilled) {
                            $sell->update([
                                'quantity'     => $quantity,
                                'price'        => $filledPrice,
                                'fee'          => $fee,
                                'fee_currency' => $feeCurrency,
                                'status'       => 'FILLED',
                                'filled_at'    => now(),
                            ]);

                            $this->info('ОРДЕР SELL ИСПОЛНЕН (SELL ORDER FILLED)');
                            
                            // Уведомление в Telegram
                            $telegram->notifyFilled('SELL', $bot->symbol, $quantity, $filledPrice, $fee);
                            
                            logger()->info('SELL order filled', [
                                'bot_id' => $bot->id,
                                'trade_id' => $sell->id,
                                'order_id' => $orderId,
                                'quantity' => $quantity,
                                'price' => $filledPrice,
                                'fee' => $fee,
                            ]);
                        } else {
                            $orderStatus = $exchange === 'bybit' 
                                ? ($order['orderStatus'] ?? 'Unknown')
                                : ($order['state'] ?? 'Unknown');
                            
                            $sell->update([
                                'status' => 'SENT',
                            ]);

                            $this->info('ОРДЕР SELL ОТПРАВЛЕН (SELL ORDER SENT)');
                            $this->warn('Статус ордера (Order status): ' . $orderStatus);
                            
                            logger()->info('SELL order sent (not filled yet)', [
                                'bot_id' => $bot->id,
                                'trade_id' => $sell->id,
                                'order_id' => $orderId,
                                'status' => $orderStatus,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->warn('Ошибка проверки статуса SELL ордера (SELL order status check error): ' . $e->getMessage());
                        logger()->error('SELL order status check failed', [
                            'bot_id' => $bot->id,
                            'trade_id' => $sell->id,
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } catch (\Throwable $e) {
                    $telegram->notifyError('SELL Order', $e->getMessage());

                    $sell->update([
                        'status'            => 'FAILED',
                        'exchange_response' => [
                            'error' => $e->getMessage(),
                        ],
                    ]);

                    $this->error('Исключение SELL (SELL exception): ' . $e->getMessage());

                    logger()->error('SELL failed', [
                        'bot_id' => $bot->id,
                        'error'  => $e->getMessage(),
                    ]);
                }

                $actionTaken = true; // Действие выполнено
                continue;
            }

            // HOLD сигнал - No action taken (только если не было других действий)
            if (!$actionTaken) {
                $this->info('Действий не предпринято (No action taken)');
            }
            
            // Логирование перед отправкой HOLD
            logger()->info('Sending HOLD notification', [
                'bot_id' => $bot->id,
                'symbol' => $bot->symbol,
                'price' => $price,
                'signal' => $signal,
                'rsi' => $lastRsi,
                'ema' => $lastEma,
            ]);
            
            // Используем сохраненные значения RSI и EMA
            try {
                $telegram->notifyHold($bot->symbol, $price, $signal, $lastRsi, $lastEma);
                logger()->info('HOLD notification sent successfully', ['bot_id' => $bot->id]);
            } catch (\Throwable $e) {
                logger()->error('Failed to send HOLD notification', [
                    'bot_id' => $bot->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error('Ошибка отправки HOLD уведомления (HOLD notification error): ' . $e->getMessage());
            }
        }

        $this->info('Все боты обработаны (All bots processed).');
        return self::SUCCESS;
    }

    /**
     * Получить причину решения стратегии
     */
    protected function getDecisionReason(string $signal, float $rsi, float $ema, float $price, float $netPosition): string
    {
        if ($signal === 'BUY') {
            if ($rsi < 30 && $price > $ema) {
                return "RSI перепродан (< 30) и цена выше EMA";
            }
            return "RSI: {$rsi}, EMA: {$ema}, Price: {$price}";
        } elseif ($signal === 'SELL') {
            if ($rsi > 70 && $price < $ema) {
                return "RSI перекуплен (> 70) и цена ниже EMA";
            }
            return "RSI: {$rsi}, EMA: {$ema}, Price: {$price}";
        } else {
            // HOLD
            $reasons = [];
            if ($rsi >= 30 && $rsi <= 70) {
                $reasons[] = "RSI в нейтральной зоне ({$rsi})";
            }
            if ($rsi < 30 && $price <= $ema) {
                $reasons[] = "RSI перепродан, но цена ниже EMA";
            }
            if ($rsi > 70 && $price >= $ema) {
                $reasons[] = "RSI перекуплен, но цена выше EMA";
            }
            return !empty($reasons) ? implode('; ', $reasons) : "Нет четкого сигнала";
        }
    }
}
