<?php

namespace App\Console\Commands;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Strategies\RsiEmaStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Бэктест стратегии RSI+EMA для фьючерсов OKX (perpetual swap).
 * Только long, без плеча в симуляции (размер позиции в USDT = маржа).
 */
class BacktestFuturesCommand extends Command
{
    protected $signature = 'strategy:futures-backtest
                            {symbol : Символ (например: BTCUSDT)}
                            {--timeframe=1h : Таймфрейм (5m, 15m, 1h, 1D)}
                            {--period=500 : Количество свечей}
                            {--rsi-period=17 : Период RSI}
                            {--ema-period=10 : Период EMA}
                            {--rsi-buy=40 : Порог RSI для покупки}
                            {--rsi-sell=60 : Порог RSI для продажи}
                            {--position-usdt=100 : Размер позиции (маржа) в USDT}
                            {--fee=0.0005 : Комиссия за сделку (0.0005 = 0.05%)}
                            {--json : Вывести результаты в JSON}';

    protected $description = 'Бэктест фьючерсов OKX (RSI+EMA, long only)';

    public function handle(): int
    {
        $symbol = strtoupper($this->argument('symbol'));
        $timeframe = $this->option('timeframe');
        $period = (int) $this->option('period');
        $rsiPeriod = (int) $this->option('rsi-period');
        $emaPeriod = (int) $this->option('ema-period');
        $rsiBuy = (float) $this->option('rsi-buy');
        $rsiSell = (float) $this->option('rsi-sell');
        $positionUsdt = (float) $this->option('position-usdt');
        $fee = (float) $this->option('fee');
        $jsonMode = $this->option('json');

        $ctVal = (float) (config("futures.contract_sizes.{$symbol}", '0.01'));
        $intervalMap = [
            '1m' => '1m', '5m' => '5m', '15m' => '15m', '30m' => '30m',
            '1h' => '1H', '60' => '1H', '2h' => '2H', '4h' => '4H',
            '1d' => '1D', '1D' => '1D', 'D' => '1D',
        ];
        $okxInterval = $intervalMap[strtolower($timeframe)] ?? $timeframe;
        $instId = $this->formatSymbolSwap($symbol);

        if (!$jsonMode) {
            $this->info("Бэктест фьючерсов (Futures backtest): {$symbol}");
            $this->line("  instId: {$instId}, период: {$period} свечей, позиция: {$positionUsdt} USDT");
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

        $results = $this->runBacktestFutures(
            $candleList,
            $rsiPeriod,
            $emaPeriod,
            $rsiBuy,
            $rsiSell,
            $positionUsdt,
            $ctVal,
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
            'position_usdt' => $positionUsdt,
            'contract_size' => $ctVal,
        ];

        if ($jsonMode) {
            $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->displayResults($results);
        return self::SUCCESS;
    }

    protected function runBacktestFutures(
        array $candles,
        int $rsiPeriod,
        int $emaPeriod,
        float $rsiBuy,
        float $rsiSell,
        float $positionUsdt,
        float $ctVal,
        float $fee,
        float $emaTolerancePercent,
        ?float $emaToleranceDeepPercent,
        ?float $rsiDeepOversold
    ): array {
        $closes = array_column($candles, 'close');
        $timestamps = array_column($candles, 'timestamp');
        $startIndex = max($rsiPeriod, $emaPeriod);

        $balance = 1000.0;
        $positionContracts = 0.0;
        $entryPrice = 0.0;
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
            $rsiVal = is_array($rsi) ? end($rsi) : $rsi;
            $emaVal = is_array($ema) ? end($ema) : $ema;
            $price = $closes[$i];
            $ts = $timestamps[$i];

            $signal = RsiEmaStrategy::decide($historicalCloses, $rsiPeriod, $emaPeriod, $rsiBuy, $rsiSell, false, 12, 26, 9, $emaTolerancePercent, $emaToleranceDeepPercent, $rsiDeepOversold);

            if ($positionContracts > 0) {
                if ($signal === 'SELL') {
                    $notional = $positionContracts * $price * $ctVal;
                    $feeCost = $notional * $fee * 2;
                    $pnl = ($price - $entryPrice) * $positionContracts * $ctVal - $feeCost;
                    $balance += $positionUsdt + $pnl;

                    $trades[] = [
                        'buy_price' => $entryPrice,
                        'sell_price' => $price,
                        'contracts' => $positionContracts,
                        'pnl' => $pnl,
                        'buy_timestamp' => $entryTimestamp,
                        'sell_timestamp' => $ts,
                    ];
                    $pnl > 0 ? $winningTrades++ : $losingTrades++;

                    $positionContracts = 0.0;
                    $entryPrice = 0.0;
                    $entryTimestamp = null;
                }
                continue;
            }

            if ($signal === 'BUY' && $balance >= $positionUsdt) {
                $contracts = $ctVal > 0 ? floor($positionUsdt / ($price * $ctVal)) : 0;
                if ($contracts < 1) {
                    $contracts = 1;
                }
                $costWithFee = $positionUsdt * (1 + $fee);
                if ($balance >= $costWithFee) {
                    $balance -= $costWithFee;
                    $positionContracts = (float) $contracts;
                    $entryPrice = $price;
                    $entryTimestamp = $ts;
                }
            }
        }

        $unrealizedPnL = 0.0;
        if ($positionContracts > 0) {
            $lastPrice = $closes[count($closes) - 1];
            $unrealizedPnL = ($lastPrice - $entryPrice) * $positionContracts * $ctVal;
            $balance += $positionUsdt + $unrealizedPnL;
        }

        $totalTrades = count($trades);
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        $totalPnL = array_sum(array_column($trades, 'pnl')) + $unrealizedPnL;
        $returnPercent = (($balance - 1000.0) / 1000.0) * 100;

        return [
            'initial_balance' => 1000.0,
            'final_balance' => $balance,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl' => $totalPnL,
            'return_percent' => $returnPercent,
            'trades' => $trades,
            'unrealized_pnl' => $unrealizedPnL,
        ];
    }

    protected function formatSymbolSwap(string $symbol): string
    {
        if (str_contains($symbol, '-')) {
            return str_ends_with($symbol, '-SWAP') ? $symbol : $symbol . '-SWAP';
        }
        if (str_ends_with($symbol, 'USDT')) {
            return substr($symbol, 0, -4) . '-USDT-SWAP';
        }
        return $symbol . '-SWAP';
    }

    protected function displayResults(array $results): void
    {
        $this->line(str_repeat('=', 60));
        $this->info('РЕЗУЛЬТАТЫ БЭКТЕСТА ФЬЮЧЕРСОВ (FUTURES BACKTEST RESULTS)');
        $this->line(str_repeat('=', 60));
        $this->line('Начальный баланс: ' . number_format($results['initial_balance'], 2) . ' USDT');
        $this->line('Конечный баланс: ' . number_format($results['final_balance'], 2) . ' USDT');
        $this->line('Total PnL: ' . number_format($results['total_pnl'], 2) . ' USDT');
        $this->line('Доходность: ' . number_format($results['return_percent'], 2) . '%');
        $this->line('Сделок: ' . $results['total_trades'] . ', Win Rate: ' . number_format($results['win_rate'], 2) . '%');
        if (!empty($results['trades'])) {
            $this->line('Последние 5 сделок:');
            foreach (array_slice($results['trades'], -5) as $t) {
                $this->line(sprintf('  BUY %s → SELL %s | PnL: %s USDT', number_format($t['buy_price'], 2), number_format($t['sell_price'], 2), number_format($t['pnl'], 2)));
            }
        }
        $this->line(str_repeat('=', 60));
    }
}
