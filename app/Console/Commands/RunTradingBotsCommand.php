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
        $this->info('Starting trading bots...');

        $bots = TradingBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get();

        if ($bots->isEmpty()) {
            $this->warn('No active bots found');
            return self::SUCCESS;
        }

        foreach ($bots as $bot) {

            $this->line(str_repeat('-', 30));
            $this->info("Bot #{$bot->id}");
            $this->line("Symbol: {$bot->symbol}");
            $this->line("Position size (USDT): {$bot->position_size}");

            if (! $bot->exchangeAccount) {
                $this->error('No exchange account attached');
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
                $this->error('Price error: ' . $e->getMessage());
                continue;
            }

            $this->line("Current price: {$price}");

            /*
            |--------------------------------------------------------------------------
            | CANDLES
            |--------------------------------------------------------------------------
            */
            try {
                $candles = $exchangeService->getCandles($bot->symbol, $bot->timeframe, 100);
            } catch (\Throwable $e) {
                $this->error('Candles error: ' . $e->getMessage());
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
                $this->warn('Not enough candle data');
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
            $rsi = RsiIndicator::calculate($closes);
            $ema = EmaIndicator::calculate($closes, 20);

            $this->line('RSI: ' . round($rsi, 2));
            $this->line('EMA: ' . round($ema, 2));

            /*
            |--------------------------------------------------------------------------
            | STRATEGY
            |--------------------------------------------------------------------------
            */
            $signal = RsiEmaStrategy::decide($closes);
            $this->info("Signal: {$signal}");

            /*
            |--------------------------------------------------------------------------
            | POSITION STATE
            |--------------------------------------------------------------------------
            */
            $netPosition = $positionManager->getNetPosition();
            $this->line('Net position (BTC): ' . $netPosition);

            /*
            |--------------------------------------------------------------------------
            | BUY
            |--------------------------------------------------------------------------
            */
            if ($signal === 'BUY') {

                if (! $positionManager->canBuy()) {
                    $this->warn('BUY skipped: position already open');
                    $telegram->notifySkip('BUY', 'Position already open');
                    continue;
                }

                $usdtAmount = (float) $bot->position_size;

                if ($usdtAmount <= 0) {
                    $this->warn('Invalid position size');
                    $telegram->notifySkip('BUY', 'Invalid position size');
                    continue;
                }

                // Проверка минимальной суммы
                $minNotional = config('trading.min_notional_usdt', 1);
                if ($usdtAmount < $minNotional) {
                    $this->warn("BUY skipped: amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT");
                    $telegram->notifySkip('BUY', "Amount {$usdtAmount} USDT is less than minimum {$minNotional} USDT");
                    continue;
                }

                // Проверка баланса перед покупкой
                if (config('trading.real_trading') && ! $bot->dry_run) {
                    try {
                        $balance = $exchangeService->getBalance('USDT');
                        $this->line("USDT Balance: {$balance}");

                        if ($balance < $usdtAmount) {
                            $this->error("BUY skipped: insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT");
                            logger()->warning('Insufficient balance for BUY', [
                                'bot_id' => $bot->id,
                                'required' => $usdtAmount,
                                'available' => $balance,
                            ]);
                            $telegram->notifySkip('BUY', "Insufficient balance. Required: {$usdtAmount} USDT, Available: {$balance} USDT");
                            continue;
                        }
                    } catch (\Throwable $e) {
                        $this->error('Balance check failed: ' . $e->getMessage());
                        logger()->error('Balance check error', [
                            'bot_id' => $bot->id,
                            'error' => $e->getMessage(),
                        ]);
                        $telegram->notifyError('BUY Balance Check', $e->getMessage());
                        continue;
                    }
                }

                if (! config('trading.real_trading') || $bot->dry_run) {
                    $this->warn("DRY RUN BUY {$usdtAmount} USDT");
                    $telegram->notifyBuy($bot->symbol, $usdtAmount, $price, true);
                    continue;
                }

                $this->warn("REAL BUY EXECUTING ({$usdtAmount} USDT)");

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
                            $this->error('Bybit error: ' . json_encode($response));
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
                            $this->error('OKX error: ' . json_encode($response));
                            continue;
                        }
                        $orderId = $response['data'][0]['ordId'] ?? null;
                    } else {
                        $this->error('Unsupported exchange: ' . $exchange);
                        continue;
                    }

                    if (! $orderId) {
                        $trade->update([
                            'status' => 'FAILED',
                        ]);
                        $this->error("{$exchange} did not return orderId");
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
                        $this->warn('Order not found yet');
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

                        $this->info('BUY ORDER FILLED');
                        
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

                        $this->info('BUY ORDER SENT');
                        $this->warn('Order status: ' . $orderStatus);
                        
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

                    $this->error('BUY exception: ' . $e->getMessage());
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
                    $this->line('No open BUY position — skip SELL');
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
                    $this->line('SELL already in progress — skip');
                    $telegram->notifySkip('SELL', 'SELL already in progress');
                    continue;
                }

                // Получаем реальный баланс BTC с биржи (более точный)
                try {
                    $baseCoin = str_replace('USDT', '', $bot->symbol);
                    $btcQty = $exchangeService->getBalance($baseCoin);
                    $this->line("Available {$baseCoin} balance: {$btcQty}");
                } catch (\Throwable $e) {
                    $this->error('Balance check failed: ' . $e->getMessage());
                    $telegram->notifyError('SELL Balance Check', $e->getMessage());
                    // Fallback: используем netPosition из БД
                    $btcQty = $positionManager->getNetPosition();
                    $this->warn("Using net position from DB: {$btcQty}");
                }

                if ($btcQty <= 0) {
                    $this->line('No balance available — skip SELL');
                    $telegram->notifySkip('SELL', 'No balance available');
                    continue;
                }

                // Проверка dry_run для SELL
                if (! config('trading.real_trading') || $bot->dry_run) {
                    $this->warn("DRY RUN SELL {$btcQty} {$baseCoin}");
                    $telegram->notifySell($bot->symbol, $btcQty, $price, true);
                    continue;
                }

                $this->warn("REAL SELL EXECUTING ({$btcQty} {$baseCoin})");

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

                    $sell->update([
                        'order_id'          => $orderId,
                        'status'            => $orderId ? 'SENT' : 'FAILED',
                        'exchange_response' => $response,
                    ]);

                    // даём бирже обработать ордер
                    usleep(500_000);

                } catch (\Throwable $e) {
                    $telegram->notifyError('SELL Order', $e->getMessage());

                    $sell->update([
                        'status'            => 'FAILED',
                        'exchange_response' => [
                            'error' => $e->getMessage(),
                        ],
                    ]);

                    $this->error('SELL exception: ' . $e->getMessage());

                    logger()->error('SELL failed', [
                        'bot_id' => $bot->id,
                        'error'  => $e->getMessage(),
                    ]);
                }

                continue;
            }

            $this->info('No action taken');
        }

        $this->info('All bots processed.');
        return self::SUCCESS;
    }
}
