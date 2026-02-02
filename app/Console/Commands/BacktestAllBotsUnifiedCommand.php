<?php

namespace App\Console\Commands;

use App\Models\BtcQuoteBot;
use App\Models\FuturesBot;
use App\Models\TradingBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Одна команда бэктеста для всех типов ботов: spot, futures, BTC-quote.
 */
class BacktestAllBotsUnifiedCommand extends Command
{
    protected $signature = 'strategy:backtest-all-bots
                            {--period=500 : Количество свечей}
                            {--exchange=okx : Биржа для spot (okx или bybit)}
                            {--output= : Файл для сохранения всех результатов (JSON)}';

    protected $description = 'Бэктест всех ботов: spot, фьючерсы, пары за BTC (одна команда)';

    public function handle(): int
    {
        $period = (int) $this->option('period');
        $exchange = $this->option('exchange');
        $outputFile = $this->option('output');

        $this->info('Бэктест всех ботов (spot + futures + btc-quote), period=' . $period);
        $this->line('');

        $allSpot = [];
        $allFutures = [];
        $allBtcQuote = [];

        // --- SPOT ---
        $spotBots = TradingBot::all();
        if ($spotBots->isNotEmpty()) {
            $this->line(str_repeat('=', 60));
            $this->info('SPOT БОТЫ (' . $spotBots->count() . ')');
            $this->line(str_repeat('=', 60));
            foreach ($spotBots as $bot) {
                $r = $this->runSpotBacktest($bot, $period, $exchange);
                if ($r !== null) {
                    $allSpot[] = $r;
                    $res = $r['results'];
                    $this->line('  ' . $bot->symbol . ' — Return: ' . number_format($res['return_percent'] ?? 0, 2) . '% | Trades: ' . ($res['total_trades'] ?? 0) . ' | PnL: ' . number_format($res['total_pnl'] ?? 0, 2) . ' USDT');
                } else {
                    $this->warn('  ' . $bot->symbol . ' — ошибка или не удалось распарсить');
                }
            }
            $this->line('');
        }

        // --- FUTURES ---
        $futuresBots = FuturesBot::with('exchangeAccount')
            ->get()
            ->filter(fn ($b) => $b->exchangeAccount && $b->exchangeAccount->exchange === 'okx');
        if ($futuresBots->isNotEmpty()) {
            $this->line(str_repeat('=', 60));
            $this->info('ФЬЮЧЕРСЫ (' . $futuresBots->count() . ')');
            $this->line(str_repeat('=', 60));
            foreach ($futuresBots as $bot) {
                $r = $this->runFuturesBacktest($bot, $period);
                if ($r !== null) {
                    $allFutures[] = $r;
                    $res = $r['results'];
                    $this->line('  ' . $bot->symbol . ' — Return: ' . number_format($res['return_percent'] ?? 0, 2) . '% | Trades: ' . ($res['total_trades'] ?? 0) . ' | PnL: ' . number_format($res['total_pnl'] ?? 0, 2) . ' USDT');
                } else {
                    $this->warn('  ' . $bot->symbol . ' — ошибка');
                }
            }
            $this->line('');
        }

        // --- BTC-QUOTE ---
        $btcBots = BtcQuoteBot::with('exchangeAccount')
            ->get()
            ->filter(fn ($b) => $b->exchangeAccount && $b->exchangeAccount->exchange === 'okx');
        if ($btcBots->isNotEmpty()) {
            $this->line(str_repeat('=', 60));
            $this->info('ПАРЫ ЗА BTC (' . $btcBots->count() . ')');
            $this->line(str_repeat('=', 60));
            foreach ($btcBots as $bot) {
                $r = $this->runBtcQuoteBacktest($bot, $period);
                if ($r !== null) {
                    $allBtcQuote[] = $r;
                    $res = $r['results'];
                    $pnl = isset($res['total_pnl_usdt']) ? number_format($res['total_pnl_usdt'], 2) . ' USDT' : number_format($res['total_pnl_btc'] ?? 0, 8) . ' BTC';
                    $this->line('  ' . $bot->symbol . ' — Return: ' . number_format($res['return_percent'] ?? 0, 2) . '% | Trades: ' . ($res['total_trades'] ?? 0) . ' | PnL: ' . $pnl);
                } else {
                    $this->warn('  ' . $bot->symbol . ' — ошибка');
                }
            }
            $this->line('');
        }

        if (empty($allSpot) && empty($allFutures) && empty($allBtcQuote)) {
            $this->warn('Нет ботов или все бэктесты завершились с ошибкой.');
            return self::FAILURE;
        }

        $this->displaySummary($allSpot, $allFutures, $allBtcQuote);

        if ($outputFile) {
            $out = [
                'spot' => $allSpot,
                'futures' => $allFutures,
                'btc_quote' => $allBtcQuote,
                'period' => $period,
            ];
            file_put_contents($outputFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Результаты сохранены: ' . $outputFile);
        }

        return self::SUCCESS;
    }

    protected function runSpotBacktest(TradingBot $bot, int $period, string $exchange): ?array
    {
        $rsiPeriod = $bot->rsi_period ?? 17;
        $emaPeriod = $bot->ema_period ?? 10;
        $positionSize = (float) $bot->position_size;
        $stopLoss = $bot->stop_loss_percent ? (float) $bot->stop_loss_percent : null;
        $takeProfit = $bot->take_profit_percent ? (float) $bot->take_profit_percent : null;
        $rsiBuy = $bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 45.0;
        $rsiSell = $bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 55.0;
        $useMacdFilter = (bool) ($bot->use_macd_filter ?? false);
        $emaTolerance = (float) (config('trading.ema_tolerance_percent', 1));
        $emaToleranceDeep = config('trading.ema_tolerance_deep_percent');
        $rsiDeepOversold = config('trading.rsi_deep_oversold');

        $params = [
            'symbol' => $bot->symbol,
            '--timeframe' => $bot->timeframe,
            '--exchange' => $exchange,
            '--period' => $period,
            '--rsi-period' => $rsiPeriod,
            '--ema-period' => $emaPeriod,
            '--rsi-buy-threshold' => $rsiBuy,
            '--rsi-sell-threshold' => $rsiSell,
            '--position-size' => $positionSize,
            '--stop-loss' => $stopLoss ?: '',
            '--take-profit' => $takeProfit ?: '',
            '--ema-tolerance' => $emaTolerance,
            '--json' => true,
        ];
        if ($useMacdFilter) {
            $params['--use-macd-filter'] = true;
        }
        if ($emaToleranceDeep !== null && $emaToleranceDeep !== '' && $rsiDeepOversold !== null && $rsiDeepOversold !== '') {
            $params['--ema-tolerance-deep'] = $emaToleranceDeep;
            $params['--rsi-deep-oversold'] = $rsiDeepOversold;
        }

        try {
            Artisan::call('strategy:backtest', $params);
            $output = trim(Artisan::output());
            $result = $this->parseBacktestJson($output);
            if ($result !== null && isset($result['return_percent'])) {
                return [
                    'bot_id' => $bot->id,
                    'symbol' => $bot->symbol,
                    'timeframe' => $bot->timeframe,
                    'type' => 'spot',
                    'results' => $result,
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    protected function runFuturesBacktest(FuturesBot $bot, int $period): ?array
    {
        $rsiPeriod = $bot->rsi_period ?? 17;
        $emaPeriod = $bot->ema_period ?? 10;
        $rsiBuy = $bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 40.0;
        $rsiSell = $bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 60.0;
        $positionUsdt = (float) $bot->position_size_usdt;

        try {
            Artisan::call('strategy:futures-backtest', [
                'symbol' => $bot->symbol,
                '--timeframe' => $bot->timeframe,
                '--period' => $period,
                '--rsi-period' => $rsiPeriod,
                '--ema-period' => $emaPeriod,
                '--rsi-buy' => $rsiBuy,
                '--rsi-sell' => $rsiSell,
                '--position-usdt' => $positionUsdt,
                '--json' => true,
            ]);
            $output = trim(Artisan::output());
            $decoded = json_decode($output, true);
            if ($decoded !== null && isset($decoded['return_percent']) && !isset($decoded['error'])) {
                return [
                    'bot_id' => $bot->id,
                    'symbol' => $bot->symbol,
                    'timeframe' => $bot->timeframe,
                    'type' => 'futures',
                    'results' => $decoded,
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    protected function runBtcQuoteBacktest(BtcQuoteBot $bot, int $period): ?array
    {
        $rsiPeriod = $bot->rsi_period ?? 17;
        $emaPeriod = $bot->ema_period ?? 10;
        $rsiBuy = $bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 40.0;
        $rsiSell = $bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 60.0;
        $positionBtc = (float) $bot->position_size_btc;

        try {
            Artisan::call('strategy:btc-quote-backtest', [
                'symbol' => $bot->symbol,
                '--timeframe' => $bot->timeframe,
                '--period' => $period,
                '--rsi-period' => $rsiPeriod,
                '--ema-period' => $emaPeriod,
                '--rsi-buy' => $rsiBuy,
                '--rsi-sell' => $rsiSell,
                '--position-btc' => $positionBtc,
                '--json' => true,
            ]);
            $output = trim(Artisan::output());
            $decoded = json_decode($output, true);
            if ($decoded !== null && isset($decoded['return_percent']) && !isset($decoded['error'])) {
                return [
                    'bot_id' => $bot->id,
                    'symbol' => $bot->symbol,
                    'timeframe' => $bot->timeframe,
                    'type' => 'btc_quote',
                    'results' => $decoded,
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    protected function parseBacktestJson(string $output): ?array
    {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '"return_percent"') || str_contains($line, '"error"')) {
                continue;
            }
            if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                $decoded = json_decode($line, true);
                if ($decoded !== null && isset($decoded['return_percent']) && !isset($decoded['error']) && json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        $decoded = json_decode($output, true);
        if ($decoded !== null && isset($decoded['return_percent']) && !isset($decoded['error'])) {
            return $decoded;
        }
        return null;
    }

    protected function displaySummary(array $allSpot, array $allFutures, array $allBtcQuote): void
    {
        $this->line(str_repeat('=', 60));
        $this->info('ИТОГО (SUMMARY)');
        $this->line(str_repeat('=', 60));

        $total = count($allSpot) + count($allFutures) + count($allBtcQuote);
        $this->line('Всего бэктестов: ' . $total . ' (spot: ' . count($allSpot) . ', futures: ' . count($allFutures) . ', btc-quote: ' . count($allBtcQuote) . ')');
        $this->line('');

        foreach (['Spot' => $allSpot, 'Futures' => $allFutures, 'BTC-quote' => $allBtcQuote] as $label => $list) {
            if (empty($list)) {
                continue;
            }
            usort($list, function ($a, $b) {
                $ra = $a['results']['return_percent'] ?? 0;
                $rb = $b['results']['return_percent'] ?? 0;
                return $rb <=> $ra;
            });
            $this->info($label . ' — ТОП по доходности:');
            foreach (array_slice($list, 0, 5) as $i => $r) {
                $res = $r['results'];
                $pnl = $res['total_pnl'] ?? $res['total_pnl_usdt'] ?? $res['total_pnl_btc'] ?? 0;
                $this->line('  ' . ($i + 1) . '. ' . $r['symbol'] . ' — Return: ' . number_format($res['return_percent'] ?? 0, 2) . '% | PnL: ' . (is_numeric($pnl) ? number_format($pnl, 2) : $pnl));
            }
            $this->line('');
        }
        $this->line(str_repeat('=', 60));
    }
}
