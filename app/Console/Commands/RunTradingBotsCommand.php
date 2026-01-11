<?php

namespace App\Console\Commands;

use App\Models\Trade;
use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\Bybit\BybitService;
use App\Services\Trading\PositionManager;
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

            $bybit = new BybitService($bot->exchangeAccount);
            $positionManager = new PositionManager($bot);

            /*
            |--------------------------------------------------------------------------
            | PRICE
            |--------------------------------------------------------------------------
            */
            try {
                $price = $bybit->getPrice($bot->symbol);
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
            $candles = $bybit->getCandles($bot->symbol, $bot->timeframe, 100);

            if (
                empty($candles['result']['list']) ||
                count($candles['result']['list']) < 20
            ) {
                $this->warn('Not enough candle data');
                continue;
            }

            $closes = array_map(
                fn ($candle) => (float) $candle[4],
                array_reverse($candles['result']['list'])
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
                    continue;
                }

                $usdtAmount = (float) $bot->position_size;

                if ($usdtAmount <= 0) {
                    $this->warn('Invalid position size');
                    continue;
                }

                if (! config('trading.real_trading') || $bot->dry_run) {
                    $this->warn("DRY RUN BUY {$usdtAmount} USDT");
                    continue;
                }

                $this->warn("REAL BUY EXECUTING ({$usdtAmount} USDT)");

                $trade = $bot->trades()->create([
                    'side'     => 'BUY',
                    'symbol'   => $bot->symbol,
                    'price'    => $price,   // цена на момент отправки
                    'quantity' => 0,        // узнаем после FILLED
                    'status'   => 'PENDING',
                ]);

                try {
                    $response = $bybit->placeMarketBuy(
                        $bot->symbol,
                        $usdtAmount
                    );

                    if (($response['retCode'] ?? 1) !== 0) {
                        $trade->update([
                            'status' => 'FAILED',
                            'exchange_response' => $response,
                        ]);

                        $this->error('Bybit error: ' . json_encode($response));
                        continue;
                    }

                    $orderId = $response['result']['orderId'] ?? null;

                    if (! $orderId) {
                        $trade->update([
                            'status' => 'FAILED',
                        ]);

                        $this->error('Bybit did not return orderId');
                        continue;
                    }

                    // сохраняем order_id
                    $trade->update([
                        'order_id' => $orderId,
                        'status'   => 'SENT',
                    ]);

                    // даём Bybit исполнить market-ордер
                    usleep(500_000);

                    // 9.4 — проверяем статус
                    $orderResponse = $bybit->getOrder(
                        $bot->symbol,
                        $orderId
                    );

                    $order = $orderResponse['result']['list'][0] ?? null;

                    if (! $order) {
                        $this->warn('Order not found yet');
                        continue;
                    }

                    if ($order['orderStatus'] === 'Filled') {
                        $trade->update([
                            'quantity'     => (float) $order['cumExecQty'],
                            'fee'          => (float) ($order['cumExecFee'] ?? 0),
                            'fee_currency' => $order['feeCurrency'] ?? null,
                            'status'       => 'FILLED',
                            'filled_at'    => now(),
                        ]);

                        $this->info('BUY ORDER FILLED');
                    } else {
                        $trade->update([
                            'status' => 'SENT',
                        ]);

                        $this->info('BUY ORDER SENT');
                        $this->warn('Order status: ' . $order['orderStatus']);
                    }
                } catch (\Throwable $e) {
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

                // Найти открытый BUY
                $buy = Trade::where('trading_bot_id', $bot->id)
                    ->where('side', 'BUY')
                    ->where('status', 'FILLED')
                    ->whereNull('closed_at')
                    ->first();

                if (! $buy) {
                    $this->line('No open BUY position — skip SELL');
                    continue;
                }

                // Защита от двойного SELL
                $hasPendingSell = Trade::where('parent_id', $buy->id)
                    ->whereIn('status', ['PENDING', 'SENT'])
                    ->exists();

                if ($hasPendingSell) {
                    $this->line('SELL already in progress — skip');
                    continue;
                }

                // Создаём SELL в БД (ДО API)
                $sell = Trade::create([
                    'trading_bot_id' => $bot->id,
                    'parent_id'      => $buy->id,
                    'side'           => 'SELL',
                    'symbol'         => $buy->symbol,
                    'price'          => 0, // обновится после FILLED
                    'quantity'       => $buy->quantity,
                    'status'         => 'PENDING',
                ]);

                try {
                    $btcQty = (float) $buy->quantity;

                    $response = $bybit->placeMarketSellBtc(
                        symbol: $buy->symbol,
                        //qty: (string) $buy->quantity
                        btcQty: $btcQty
                    );

                    $sell->update([
                        'order_id'          => $response['result']['orderId'] ?? null,
                        'status'            => 'SENT',
                        'exchange_response' => $response,
                    ]);

                    // даём бирже обработать ордер
                    usleep(500_000);

                } catch (\Throwable $e) {

                    $sell->update([
                        'status'            => 'FAILED',
                        'exchange_response' => [
                            'error' => $e->getMessage(),
                        ],
                    ]);

                    logger()->error('SELL failed', [
                        'bot_id' => $bot->id,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            $this->info('No action taken');
        }

        $this->info('All bots processed.');
        return self::SUCCESS;
    }
}
