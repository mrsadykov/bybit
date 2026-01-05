<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\Bybit\BybitService;
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

        $bots = TradingBot::where('is_active', true)->get();

        foreach ($bots as $bot) {

            $this->line(str_repeat('-', 30));
            $this->info("Bot #{$bot->id}");
            $this->line("Symbol: {$bot->symbol}");
            $this->line("Position size (USDT): {$bot->position_size}");

            $bybit = new BybitService($bot->exchangeAccount);

            /* ===== PRICE ===== */
            $price = $bybit->getPrice($bot->symbol);
            $this->line("Current price: {$price}");

            /* ===== CANDLES ===== */
            $candles = $bybit->getCandles($bot->symbol, $bot->timeframe, 100);

            if (empty($candles['result']['list'])) {
                $this->warn('No candles');
                continue;
            }

            $closes = array_map(
                fn ($c) => (float) $c[4],
                array_reverse($candles['result']['list'])
            );

            /* ===== INDICATORS ===== */
            $rsi = RsiIndicator::calculate($closes);
            $ema = EmaIndicator::calculate($closes, 20);

            $this->line('RSI: ' . round($rsi, 2));
            $this->line('EMA: ' . round($ema, 2));

            /* ===== STRATEGY ===== */
            $signal = RsiEmaStrategy::decide($closes);
            $this->info("Signal: {$signal}");

            if ($signal !== 'BUY') {
                $this->info('No BUY signal');
                continue;
            }

            $usdtAmount = (float) $bot->position_size;

            // ⚠️ Реальное ограничение Bybit
//            if ($usdtAmount < 50) {
//                $this->warn('Bybit BTCUSDT Spot требует ~50 USDT минимум');
//                continue;
//            }

            $this->warn("REAL BUY EXECUTING ({$usdtAmount} USDT)");

            $response = $bybit->placeMarketBuy(
                $bot->symbol,
                $usdtAmount
            );

            if (($response['retCode'] ?? 1) !== 0) {
                $this->error('Bybit error: ' . json_encode($response));
                continue;
            }

            $this->info('BUY ORDER SENT SUCCESSFULLY');
        }

        $this->info('All bots processed.');
        return self::SUCCESS;
    }
}
