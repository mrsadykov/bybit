<?php

namespace App\Console\Commands;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Strategies\RsiEmaStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Бэктест стратегии RSI+EMA для пар в BTC (SOL-BTC, ETH-BTC и т.д.) на OKX.
 * Баланс и PnL в BTC; в конце можно показать эквивалент в USDT.
 */
class BacktestBtcQuoteCommand extends Command
{
    protected $signature = 'strategy:btc-quote-backtest
                            {symbol : Символ в BTC (например: SOLBTC или SOL-BTC)}
                            {--timeframe=1h : Таймфрейм (5m, 15m, 1h, 1D)}
                            {--period=500 : Количество свечей}
                            {--rsi-period=17 : Период RSI}
                            {--ema-period=10 : Период EMA}
                            {--rsi-buy=40 : Порог RSI для покупки}
                            {--rsi-sell=60 : Порог RSI для продажи}
                            {--position-btc=0.01 : Размер позиции в BTC}
                            {--fee=0.001 : Комиссия (0.001 = 0.1%)}
                            {--json : Вывести результаты в JSON}';

    protected $description = 'Бэктест пар за BTC на OKX (RSI+EMA)';

    public function handle(): int
    {
        $symbolRaw = $this->argument('symbol');
        $symbol = $this->normalizeBtcQuoteSymbol($symbolRaw);
        $timeframe = $this->option('timeframe');
        $period = (int) $this->option('period');
        $rsiPeriod = (int) $this->option('rsi-period');
        $emaPeriod = (int) $this->option('ema-period');
        $rsiBuy = (float) $this->option('rsi-buy');
        $rsiSell = (float) $this->option('rsi-sell');
        $positionBtc = (float) $this->option('position-btc');
        $fee = (float) $this->option('fee');
        $jsonMode = $this->option('json');

        $instId = $this->formatOkxBtcPair($symbol);
        $intervalMap = [
            '1m' => '1m', '5m' => '5m', '15m' => '15m', '30m' => '30m',
            '1h' => '1H', '60' => '1H', '2h' => '2H', '4h' => '4H',
            '1d' => '1D', '1D' => '1D', 'D' => '1D',
        ];
        $okxInterval = $intervalMap[strtolower($timeframe)] ?? $timeframe;

        if (!$jsonMode) {
            $this->info("Бэктест пар за BTC (BTC-quote backtest): {$symbol}");
            $this->line("  instId: {$instId}, период: {$period} свечей, позиция: {$positionBtc} BTC");
        }

        try {
            $limit = min($period + 50, 1000);
            $url = 'https://www.okx.com/api/v5/market/candles?' . http_build_query([
                'instId' => $instId,
                'bar' => $okxInterval,
                'limit' => (string) $limit,
            ]);
            $response = Http::timeout(15)->withoutVerifying()->get($url);
            if (!$response->successful()) {
                throw new \RuntimeException('OKX API HTTP ' . $response->status());
            }
            $json = $response->json();
            if (($json['code'] ?? '0') !== '0') {
                throw new \RuntimeException('OKX API: ' . ($json['msg'] ?? 'error'));
            }
            $rawCandles = $json['data'] ?? [];
            if (empty($rawCandles)) {
                throw new \RuntimeException('No candles');
            }
        } catch (\Throwable $e) {
            if ($jsonMode) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Ошибка данных: ' . $e->getMessage());
            }
            return self::FAILURE;
        }

        $candleList = [];
        foreach ($rawCandles as $c) {
            $candleList[] = [
                'timestamp' => (int) $c[0],
                'open' => (float) $c[1],
                'high' => (float) $c[2],
                'low' => (float) $c[3],
                'close' => (float) $c[4],
                'volume' => (float) ($c[5] ?? 0),
            ];
        }
        usort($candleList, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        $candleList = array_slice($candleList, -$period);

        $minCandles = max($rsiPeriod, $emaPeriod);
        if (count($candleList) < $minCandles) {
            if ($jsonMode) {
                $this->line(json_encode(['error' => 'Not enough candles', 'count' => count($candleList), 'min' => $minCandles], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Недостаточно свечей: ' . count($candleList) . ', нужно минимум ' . $minCandles);
            }
            return self::FAILURE;
        }

        $emaTolerance = (float) (config('trading.ema_tolerance_percent', 1));
        $emaToleranceDeep = config('trading.ema_tolerance_deep_percent') ? (float) config('trading.ema_tolerance_deep_percent') : null;
        $rsiDeepOversold = config('trading.rsi_deep_oversold') !== null ? (float) config('trading.rsi_deep_oversold') : null;

        $results = $this->runBacktestBtcQuote(
            $candleList,
            $rsiPeriod,
            $emaPeriod,
            $rsiBuy,
            $rsiSell,
            $positionBtc,
            $fee,
            $emaTolerance,
            $emaToleranceDeep,
            $rsiDeepOversold
        );

        $results['parameters'] = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'period' => $period,
            'rsi_period' => $rsiPeriod,
            'ema_period' => $emaPeriod,
            'position_btc' => $positionBtc,
        ];

        if ($jsonMode) {
            $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->displayResults($results);
        return self::SUCCESS;
    }

    protected function runBacktestBtcQuote(
        array $candles,
        int $rsiPeriod,
        int $emaPeriod,
        float $rsiBuy,
        float $rsiSell,
        float $positionBtc,
        float $fee,
        float $emaTolerancePercent,
        ?float $emaToleranceDeepPercent,
        ?float $rsiDeepOversold
    ): array {
        $closes = array_column($candles, 'close');
        $timestamps = array_column($candles, 'timestamp');
        $startIndex = max($rsiPeriod, $emaPeriod);

        $balanceBtc = 0.1;
        $positionQty = 0.0;
        $entryPriceBtc = 0.0;
        $entryTimestamp = null;
        $trades = [];
        $winningTrades = 0;
        $losingTrades = 0;

        for ($i = $startIndex; $i < count($closes); $i++) {
            $historicalCloses = array_slice($closes, 0, $i + 1);
            try {
                $rsi = RsiIndicator::calculate($historicalCloses, $rsiPeriod);
                $ema = EmaIndicator::calculate($historicalCloses, $emaPeriod);
            } catch (\Throwable $e) {
                continue;
            }
            $price = $closes[$i];
            $ts = $timestamps[$i];

            $signal = RsiEmaStrategy::decide($historicalCloses, $rsiPeriod, $emaPeriod, $rsiBuy, $rsiSell, false, 12, 26, 9, $emaTolerancePercent, $emaToleranceDeepPercent, $rsiDeepOversold);

            if ($positionQty > 0) {
                if ($signal === 'SELL') {
                    $btcReceived = $positionQty * $price * (1 - $fee);
                    $pnlBtc = $btcReceived - ($positionQty * $entryPriceBtc * (1 + $fee));
                    $balanceBtc += $btcReceived;

                    $trades[] = [
                        'buy_price_btc' => $entryPriceBtc,
                        'sell_price_btc' => $price,
                        'quantity' => $positionQty,
                        'pnl_btc' => $pnlBtc,
                        'buy_timestamp' => $entryTimestamp,
                        'sell_timestamp' => $ts,
                    ];
                    $pnlBtc > 0 ? $winningTrades++ : $losingTrades++;

                    $positionQty = 0.0;
                    $entryPriceBtc = 0.0;
                    $entryTimestamp = null;
                }
                continue;
            }

            if ($signal === 'BUY' && $balanceBtc >= $positionBtc) {
                $costBtc = $positionBtc * (1 + $fee);
                if ($balanceBtc >= $costBtc && $price > 0) {
                    $balanceBtc -= $costBtc;
                    $positionQty = ($positionBtc / $price) * (1 - $fee);
                    $entryPriceBtc = $price;
                    $entryTimestamp = $ts;
                }
            }
        }

        $unrealizedPnLBtc = 0.0;
        if ($positionQty > 0) {
            $lastPrice = $closes[count($closes) - 1];
            $unrealizedPnLBtc = $positionQty * $lastPrice * (1 - $fee) - ($positionQty * $entryPriceBtc * (1 + $fee));
            $balanceBtc += $positionQty * $lastPrice * (1 - $fee);
        }

        $totalTrades = count($trades);
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        $totalPnLBtc = array_sum(array_column($trades, 'pnl_btc')) + $unrealizedPnLBtc;
        $returnPercentBtc = 0.1 > 0 ? ($totalPnLBtc / 0.1) * 100 : 0;

        $btcUsdtPrice = null;
        try {
            $r = Http::timeout(5)->get('https://www.okx.com/api/v5/market/ticker?instId=BTC-USDT');
            $data = $r->json();
            if (($data['code'] ?? '0') === '0' && !empty($data['data'][0]['last'])) {
                $btcUsdtPrice = (float) $data['data'][0]['last'];
            }
        } catch (\Throwable $e) {
        }

        $totalPnLUsdt = $btcUsdtPrice !== null ? $totalPnLBtc * $btcUsdtPrice : null;

        return [
            'initial_balance_btc' => 0.1,
            'final_balance_btc' => $balanceBtc,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl_btc' => $totalPnLBtc,
            'total_pnl_usdt' => $totalPnLUsdt,
            'return_percent' => $returnPercentBtc,
            'return_percent_btc' => $returnPercentBtc,
            'trades' => $trades,
            'unrealized_pnl_btc' => $unrealizedPnLBtc,
            'btc_usdt_at_end' => $btcUsdtPrice,
        ];
    }

    protected function normalizeBtcQuoteSymbol(string $symbol): string
    {
        $symbol = strtoupper(str_replace('-', '', $symbol));
        if (!str_ends_with($symbol, 'BTC')) {
            return $symbol . 'BTC';
        }
        return $symbol;
    }

    protected function formatOkxBtcPair(string $symbol): string
    {
        if (str_contains($symbol, '-')) {
            return $symbol;
        }
        if (str_ends_with($symbol, 'BTC')) {
            $base = substr($symbol, 0, -3);
            return $base . '-BTC';
        }
        return $symbol;
    }

    protected function displayResults(array $results): void
    {
        $this->line(str_repeat('=', 60));
        $this->info('РЕЗУЛЬТАТЫ БЭКТЕСТА ЗА BTC (BTC-QUOTE BACKTEST RESULTS)');
        $this->line(str_repeat('=', 60));
        $this->line('Начальный баланс: ' . number_format($results['initial_balance_btc'], 8) . ' BTC');
        $this->line('Конечный баланс: ' . number_format($results['final_balance_btc'], 8) . ' BTC');
        $this->line('Total PnL (BTC): ' . number_format($results['total_pnl_btc'], 8) . ' BTC');
        if ($results['total_pnl_usdt'] !== null) {
            $this->line('Total PnL (USDT): ' . number_format($results['total_pnl_usdt'], 2) . ' USDT');
        }
        $this->line('Доходность (BTC): ' . number_format($results['return_percent_btc'], 2) . '%');
        $this->line('Сделок: ' . $results['total_trades'] . ', Win Rate: ' . number_format($results['win_rate'], 2) . '%');
        if (!empty($results['trades'])) {
            $this->line('Последние 5 сделок:');
            foreach (array_slice($results['trades'], -5) as $t) {
                $this->line(sprintf('  BUY %s → SELL %s BTC | PnL: %s BTC', number_format($t['buy_price_btc'], 8), number_format($t['sell_price_btc'], 8), number_format($t['pnl_btc'], 8)));
            }
        }
        $this->line(str_repeat('=', 60));
    }
}
