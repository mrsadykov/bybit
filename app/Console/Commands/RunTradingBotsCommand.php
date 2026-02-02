<?php

namespace App\Console\Commands;

use App\Models\BotDecisionLog;
use App\Models\Trade;
use App\Support\RetryHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
            Cache::put('health_last_bots_run', now()->timestamp, now()->addDay());
            return self::SUCCESS;
        }

        // Уведомление о запуске команды
        $telegram = new TelegramService();
        $telegram->notifyBotRunStart($bots->count());

        foreach ($bots as $bot) {
            try {
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

            // Проверка лимитов риска по боту: при достижении — пропуск бота до следующего дня (daily loss) или до ручного изменения (drawdown)
            if ($this->isBotPausedByRiskLimits($bot, $telegram)) {
                BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', null, null, null, 'risk_limit');
                $this->warn("Бот {$bot->symbol} пропущен: достигнут лимит риска (Bot skipped: risk limit reached)");
                $skipNotifyKey = 'risk_skip_notify_' . $bot->id . '_' . now()->format('Y-m-d-H');
                if (!Cache::has($skipNotifyKey)) {
                    try {
                        $telegram->notifyBotSkippedRiskLimit($bot->symbol);
                        Cache::put($skipNotifyKey, true, now()->addHour());
                    } catch (\Throwable $e) {
                        logger()->warning('Telegram bot skipped risk notify failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
                    }
                }
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | PRICE
            |--------------------------------------------------------------------------
            */
            try {
                $price = RetryHelper::retry(fn () => $exchangeService->getPrice($bot->symbol), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения цены (Price error): ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('spot', $bot->symbol, $e->getMessage(), $bot->id);
                continue;
            }

            $this->line("Текущая цена (Current price): {$price}");

            /*
            |--------------------------------------------------------------------------
            | CANDLES
            |--------------------------------------------------------------------------
            */
            try {
                $candles = RetryHelper::retry(fn () => $exchangeService->getCandles($bot->symbol, $bot->timeframe, 100), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения свечей (Candles error): ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('spot', $bot->symbol, $e->getMessage(), $bot->id);
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
            $rsiBuy = $bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 40.0;
            $rsiSell = $bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 60.0;
            $useMacdFilter = (bool) ($bot->use_macd_filter ?? false);
            $emaTolerancePercent = (float) (config('trading.ema_tolerance_percent', 1));
            $emaToleranceDeepPercent = config('trading.ema_tolerance_deep_percent') !== null ? (float) config('trading.ema_tolerance_deep_percent') : null;
            $rsiDeepOversold = config('trading.rsi_deep_oversold') !== null ? (float) config('trading.rsi_deep_oversold') : null;
            $signal = RsiEmaStrategy::decide($closes, $rsiPeriod, $emaPeriod, $rsiBuy, $rsiSell, $useMacdFilter, 12, 26, 9, $emaTolerancePercent, $emaToleranceDeepPercent, $rsiDeepOversold);
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

            $decisionReason = $this->getDecisionReason($signal, $lastRsi, $lastEma, $price, $netPosition);

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
                                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'quantity_too_small_sltp');
                                    $this->warn("Количество {$btcQty} {$baseCoin} меньше минимума {$minQty} {$baseCoin} — пропуск SELL ({$reason}) (Quantity {$btcQty} {$baseCoin} is less than minimum {$minQty} {$baseCoin} — skip SELL ({$reason}))");
                                    $telegram->notifySkip('SELL', "Количество слишком мало для {$reason} (Quantity too small: {$btcQty} < {$minQty})");
                                    continue;
                                }

                                // Проверка dry_run
                                if (!config('trading.real_trading') || $bot->dry_run) {
                                    $this->warn("ТЕСТОВЫЙ РЕЖИМ SELL ({$reason}) (DRY RUN SELL) {$btcQty} {$baseCoin}");
                                    $telegram->notifySell($bot->symbol, $btcQty, $price, true);
                                } else {
                                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SELL', $price, $lastRsi, $lastEma, $reason);
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
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'position_already_open');
                    $this->warn('BUY пропущен: позиция уже открыта (BUY skipped: position already open)');
                    $telegram->notifySkip('BUY', 'Позиция уже открыта (Position already open)');
                    continue;
                }

                // Лимит открытых позиций по всем ботам пользователя
                $maxOpenTotal = config('trading.max_open_positions_total');
                if ($maxOpenTotal !== null && (int) $maxOpenTotal > 0) {
                    $openCount = Trade::whereIn('trading_bot_id', TradingBot::where('user_id', $bot->user_id)->pluck('id'))
                        ->where('side', 'BUY')
                        ->where('status', 'FILLED')
                        ->whereNull('closed_at')
                        ->count();
                    if ($openCount >= (int) $maxOpenTotal) {
                        BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'max_open_positions');
                        $this->warn("BUY пропущен: достигнут лимит открытых позиций ({$openCount}/{$maxOpenTotal}) (BUY skipped: max open positions)");
                        $cacheKey = 'risk_max_positions_notified_' . now()->format('Y-m-d-H-i');
                        if (!Cache::has($cacheKey)) {
                            try {
                                $telegram->notifyRiskLimitMaxPositions($bot->symbol, $openCount, (int) $maxOpenTotal);
                                Cache::put($cacheKey, true, now()->addMinutes(15));
                            } catch (\Throwable $e) {
                                logger()->warning('Telegram risk max positions failed', ['error' => $e->getMessage()]);
                            }
                        }
                        continue;
                    }
                }

                $usdtAmount = (float) $bot->position_size;

                if ($usdtAmount <= 0) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'invalid_position_size');
                    $this->warn('Неверный размер позиции (Invalid position size)');
                    $telegram->notifySkip('BUY', 'Неверный размер позиции (Invalid position size)');
                    continue;
                }

                // Проверка минимальной суммы
                $minNotional = config('trading.min_notional_usdt', 1);
                if ($usdtAmount < $minNotional) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'amount_below_min');
                    $this->warn("BUY пропущен: сумма {$usdtAmount} USDT меньше минимума {$minNotional} USDT (BUY skipped: amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT)");
                    $telegram->notifySkip('BUY', "Сумма {$usdtAmount} USDT меньше минимума {$minNotional} USDT (Amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT)");
                    continue;
                }

                // Проверка баланса перед покупкой
                if (config('trading.real_trading') && ! $bot->dry_run) {
                    try {
                        $balance = $exchangeService->getBalance('USDT');
                        $this->line("Баланс USDT (USDT Balance): {$balance}");

                        if ($balance < $usdtAmount) {
                            BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'insufficient_balance');
                            $this->error("BUY пропущен: недостаточно баланса. Требуется: {$usdtAmount} USDT, Доступно: {$balance} USDT (BUY skipped: insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT)");
                            logger()->warning('Insufficient balance for BUY', [
                                'bot_id' => $bot->id,
                                'required' => $usdtAmount,
                                'available' => $balance,
                            ]);
                            $telegram->notifySkip('BUY', "Недостаточно баланса. Требуется: {$usdtAmount} USDT, Доступно: {$balance} USDT (Insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT)");
                            continue;
                        }
                    } catch (\Throwable $e) {
                        $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                        logger()->error('Balance check error', [
                            'bot_id' => $bot->id,
                            'error' => $e->getMessage(),
                        ]);
                        BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'balance_check_error');
                        $telegram->notifyError('Проверка баланса BUY (BUY Balance Check)', $e->getMessage());
                        continue;
                    }
                }

                if (! config('trading.real_trading') || $bot->dry_run) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'BUY', $price, $lastRsi, $lastEma, 'dry_run');
                    $this->warn("ТЕСТОВЫЙ РЕЖИМ BUY (DRY RUN BUY) {$usdtAmount} USDT");
                    $telegram->notifyBuy($bot->symbol, $usdtAmount, $price, true);
                    continue;
                }

                BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'BUY', $price, $lastRsi, $lastEma, $decisionReason);
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
                    $telegram->notifyError('Ордер BUY (BUY Order)', $e->getMessage());
                    
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
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'no_open_buy');
                    $this->line('Нет открытой BUY позиции — пропуск SELL (No open BUY position — skip SELL)');
                    $telegram->notifySkip('SELL', 'Нет открытой BUY позиции (No open BUY position)');
                    continue;
                }

                // Защита от двойного SELL
                $hasPendingSell = Trade::where('trading_bot_id', $bot->id)
                    ->where('side', 'SELL')
                    ->whereIn('status', ['PENDING', 'SENT'])
                    ->whereNull('closed_at')
                    ->exists();

                if ($hasPendingSell) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'sell_in_progress');
                    $this->line('SELL уже выполняется — пропуск (SELL already in progress — skip)');
                    $telegram->notifySkip('SELL', 'SELL уже выполняется (SELL already in progress)');
                    continue;
                }

                // Получаем реальный баланс BTC с биржи (более точный)
                try {
                    $baseCoin = str_replace('USDT', '', $bot->symbol);
                    $btcQty = $exchangeService->getBalance($baseCoin);
                    $this->line("Доступный баланс {$baseCoin} (Available {$baseCoin} balance): {$btcQty}");
                } catch (\Throwable $e) {
                    $this->error('Ошибка проверки баланса (Balance check failed): ' . $e->getMessage());
                    $telegram->notifyError('Проверка баланса SELL (SELL Balance Check)', $e->getMessage());
                    // Fallback: используем netPosition из БД
                    $btcQty = $positionManager->getNetPosition();
                    $this->warn("Используем чистую позицию из БД (Using net position from DB): {$btcQty}");
                }

                if ($btcQty <= 0) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'no_balance');
                    $this->line('Баланс недоступен — пропуск SELL (No balance available — skip SELL)');
                    $telegram->notifySkip('SELL', 'Баланс недоступен (No balance available)');
                    continue;
                }

                // Проверка минимального размера ордера для SELL
                [$passesMin, $minQty] = $positionManager->passesMinSell($bot->symbol, $btcQty);
                if (!$passesMin) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'quantity_too_small');
                    $this->warn("Количество {$btcQty} {$baseCoin} меньше минимума {$minQty} {$baseCoin} — пропуск SELL (Quantity {$btcQty} {$baseCoin} is less than minimum {$minQty} {$baseCoin} — skip SELL)");
                    $telegram->notifySkip('SELL', "Количество слишком мало (Quantity too small: {$btcQty} < {$minQty})");
                    continue;
                }

                // Проверка dry_run для SELL
                if (! config('trading.real_trading') || $bot->dry_run) {
                    BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SELL', $price, $lastRsi, $lastEma, 'dry_run');
                    $this->warn("ТЕСТОВЫЙ РЕЖИМ SELL (DRY RUN SELL) {$btcQty} {$baseCoin}");
                    $telegram->notifySell($bot->symbol, $btcQty, $price, true);
                    continue;
                }

                BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'SELL', $price, $lastRsi, $lastEma, $decisionReason);
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
                    $telegram->notifyError('Ордер SELL (SELL Order)', $e->getMessage());

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
                BotDecisionLog::log('spot', $bot->id, $bot->symbol, 'HOLD', $price, $lastRsi, $lastEma, $decisionReason ?? '');
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
            } catch (\Throwable $e) {
                logger()->error('bots:run bot failed', ['bot_id' => $bot->id, 'symbol' => $bot->symbol, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                TelegramService::notifyBotErrorOnce('spot', $bot->symbol, $e->getMessage(), $bot->id);
                $this->error('Бот ' . $bot->symbol . ' ошибка: ' . $e->getMessage());
            }
        }

        // Алерты по лимитам (если заданы в config)
        $this->checkTradingAlerts($bots);

        Cache::put('health_last_bots_run', now()->timestamp, now()->addDay());
        $this->info('Все боты обработаны (All bots processed).');
        return self::SUCCESS;
    }

    /**
     * Проверка лимитов и отправка алертов в Telegram при достижении порогов.
     */
    protected function checkTradingAlerts(\Illuminate\Database\Eloquent\Collection $bots): void
    {
        $botIds = $bots->pluck('id');
        $dailyLossLimit = config('trading.alert_daily_loss_usdt');
        $losingStreakLimit = config('trading.alert_losing_streak_count');
        $targetProfit = config('trading.alert_target_profit_usdt');

        if ($dailyLossLimit === null && $losingStreakLimit === null && $targetProfit === null) {
            return;
        }

        $todayStart = now()->startOfDay();
        $dailyPnL = (float) Trade::whereIn('trading_bot_id', $botIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->where('closed_at', '>=', $todayStart)
            ->sum('realized_pnl');

        $closedTrades = Trade::whereIn('trading_bot_id', $botIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->orderByDesc('closed_at')
            ->limit(100)
            ->get();
        $losingStreak = 0;
        foreach ($closedTrades as $t) {
            if ((float) $t->realized_pnl < 0) {
                $losingStreak++;
            } else {
                break;
            }
        }

        $totalPnL = (float) Trade::whereIn('trading_bot_id', $botIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->sum('realized_pnl');

        $telegram = new TelegramService();
        if ($dailyLossLimit !== null && $dailyPnL <= -abs((float) $dailyLossLimit)) {
            $dailyLossCacheKey = 'telegram_alert_daily_loss_sent_' . now()->format('Y-m-d');
            if (!Cache::has($dailyLossCacheKey)) {
                try {
                    $telegram->notifyAlertDailyLoss($dailyPnL, (float) $dailyLossLimit);
                    Cache::put($dailyLossCacheKey, true, now()->endOfDay());
                } catch (\Throwable $e) {
                    logger()->warning('Telegram alert daily loss failed', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($losingStreakLimit !== null && $losingStreak >= (int) $losingStreakLimit) {
            $streakCacheKey = 'telegram_alert_losing_streak_sent_' . now()->format('Y-m-d');
            if (!Cache::has($streakCacheKey)) {
                try {
                    $telegram->notifyAlertLosingStreak($losingStreak, (int) $losingStreakLimit);
                    Cache::put($streakCacheKey, true, now()->endOfDay());
                } catch (\Throwable $e) {
                    logger()->warning('Telegram alert losing streak failed', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($targetProfit !== null && $totalPnL >= (float) $targetProfit) {
            $cacheKey = 'telegram_alert_target_profit_sent_' . now()->format('Y-m-d');
            if (!Cache::has($cacheKey)) {
                try {
                    $telegram->notifyAlertTargetProfit($totalPnL, (float) $targetProfit);
                    Cache::put($cacheKey, true, now()->endOfDay());
                } catch (\Throwable $e) {
                    logger()->warning('Telegram alert target profit failed', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Проверка лимитов риска по боту: дневной убыток и просадка.
     * При достижении лимита отправляется уведомление и возвращается true (бот пропускается).
     */
    protected function isBotPausedByRiskLimits(TradingBot $bot, TelegramService $telegram): bool
    {
        $todayStart = now()->startOfDay();

        // Лимит дневного убытка (по этому боту)
        $maxDailyLoss = $bot->max_daily_loss_usdt !== null ? (float) $bot->max_daily_loss_usdt : null;
        if ($maxDailyLoss !== null && $maxDailyLoss > 0) {
            $dailyPnL = (float) Trade::where('trading_bot_id', $bot->id)
                ->whereNotNull('closed_at')
                ->whereNotNull('realized_pnl')
                ->where('closed_at', '>=', $todayStart)
                ->sum('realized_pnl');
            if ($dailyPnL <= -$maxDailyLoss) {
                $cacheKey = 'risk_daily_loss_sent_' . $bot->id . '_' . now()->format('Y-m-d');
                if (!Cache::has($cacheKey)) {
                    try {
                        $telegram->notifyRiskLimitDailyLoss($bot->symbol, $dailyPnL, $maxDailyLoss);
                        Cache::put($cacheKey, true, now()->endOfDay());
                    } catch (\Throwable $e) {
                        logger()->warning('Telegram risk daily loss failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
                    }
                }
                return true;
            }
        }

        // Лимит просадки (по этому боту): от пика кумулятивного PnL
        $maxDrawdownPct = $bot->max_drawdown_percent !== null ? (float) $bot->max_drawdown_percent : null;
        if ($maxDrawdownPct !== null && $maxDrawdownPct > 0) {
            $closed = Trade::where('trading_bot_id', $bot->id)
                ->whereNotNull('closed_at')
                ->whereNotNull('realized_pnl')
                ->orderBy('closed_at')
                ->get();
            $cum = 0;
            $peak = 0;
            foreach ($closed as $t) {
                $cum += (float) $t->realized_pnl;
                if ($cum > $peak) {
                    $peak = $cum;
                }
            }
            if ($peak > 0.01) {
                $drawdownPct = (($peak - $cum) / $peak) * 100;
                if ($drawdownPct >= $maxDrawdownPct) {
                    $cacheKey = 'risk_drawdown_sent_' . $bot->id . '_' . now()->format('Y-m-d');
                    if (!Cache::has($cacheKey)) {
                        try {
                            $telegram->notifyRiskLimitDrawdown($bot->symbol, $drawdownPct, $maxDrawdownPct);
                            Cache::put($cacheKey, true, now()->endOfDay());
                        } catch (\Throwable $e) {
                            logger()->warning('Telegram risk drawdown failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
                        }
                    }
                    return true;
                }
            }
        }

        return false;
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
