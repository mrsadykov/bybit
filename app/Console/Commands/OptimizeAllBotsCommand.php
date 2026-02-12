<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class OptimizeAllBotsCommand extends Command
{
    protected $signature = 'strategy:optimize-all
                            {--period=1000 : Количество свечей (можно 1500, 2000 для большей выборки)}
                            {--timeframe= : Переопределить таймфрейм для всех ботов (1h, 4h, 1D)}
                            {--exchange=okx : Биржа (okx или bybit)}
                            {--trend-filter : Включить фильтр тренда в бэктесте}
                            {--volume-filter : Включить фильтр объёма в бэктесте}
                            {--sl-tp-grid : Перебор пар SL/TP (2/4, 3/6, 4/8, 5/10) в дополнение к RSI; вывод лучших SL/TP по символу}
                            {--output= : Файл для сохранения результатов (JSON)}';

    protected $description = 'Оптимизация RSI-порогов по всем ботам: перебор 38/62, 40/60, 42/58, 45/55, вывод лучшей комбинации на символ';

    /** Наборы [RSI buy, RSI sell] для перебора */
    private const RSI_THRESHOLD_SETS = [
        [38, 62],
        [40, 60],
        [42, 58],
        [45, 55],
    ];

    /** Наборы [SL%, TP%] для перебора при --sl-tp-grid */
    private const SL_TP_SETS = [
        [2, 4],
        [3, 6],
        [4, 8],
        [5, 10],
    ];

    public function handle(): int
    {
        $period = (int) $this->option('period');
        $timeframeOverride = $this->option('timeframe');
        $exchange = $this->option('exchange');
        $trendFilter = $this->option('trend-filter');
        $volumeFilter = $this->option('volume-filter');
        $slTpGrid = $this->option('sl-tp-grid');
        $outputFile = $this->option('output');

        $this->info('Оптимизация RSI-порогов по всем ботам (Optimizing RSI thresholds for all bots)...' . ($slTpGrid ? ' + перебор SL/TP' : ''));
        $this->line('');

        $bots = TradingBot::all();
        if ($bots->isEmpty()) {
            $this->warn('Торговые боты не найдены (No trading bots found)');
            return self::FAILURE;
        }

        $slTpSets = $slTpGrid ? self::SL_TP_SETS : [[null, null]];
        $totalRuns = $bots->count() * count(self::RSI_THRESHOLD_SETS) * count($slTpSets);
        $allRows = [];
        $bar = $this->output->createProgressBar($totalRuns);
        $bar->start();

        foreach ($bots as $bot) {
            $rsiPeriod = (int) ($bot->rsi_period ?? 17);
            $emaPeriod = (int) ($bot->ema_period ?? 10);
            $positionSize = (float) $bot->position_size;
            $botSl = $bot->stop_loss_percent ? (float) $bot->stop_loss_percent : null;
            $botTp = $bot->take_profit_percent ? (float) $bot->take_profit_percent : null;

            foreach (self::RSI_THRESHOLD_SETS as [$rsiBuy, $rsiSell]) {
                foreach ($slTpSets as $slTp) {
                    $bar->advance();
                    $stopLoss = $slTp[0] !== null ? $slTp[0] : $botSl;
                    $takeProfit = $slTp[1] !== null ? $slTp[1] : $botTp;

                    $timeframe = ($timeframeOverride !== null && $timeframeOverride !== '') ? $timeframeOverride : $bot->timeframe;
                    $params = [
                        'symbol' => $bot->symbol,
                        '--timeframe' => $timeframe,
                        '--exchange' => $exchange,
                        '--period' => $period,
                        '--rsi-period' => $rsiPeriod,
                        '--ema-period' => $emaPeriod,
                        '--rsi-buy-threshold' => $rsiBuy,
                        '--rsi-sell-threshold' => $rsiSell,
                        '--position-size' => $positionSize,
                        '--stop-loss' => $stopLoss !== null ? (string) $stopLoss : '',
                        '--take-profit' => $takeProfit !== null ? (string) $takeProfit : '',
                        '--json' => true,
                    ];
                    if ($trendFilter) {
                        $params['--trend-filter'] = true;
                        $params['--trend-ema-period'] = (int) (config('trading.trend_filter_ema_period') ?: 50);
                        $params['--trend-tolerance'] = (float) (config('trading.trend_filter_tolerance_percent') ?: 0);
                    }
                    if ($volumeFilter) {
                        $params['--volume-filter'] = true;
                        $params['--volume-period'] = (int) (config('trading.volume_filter_period') ?: 20);
                        $params['--volume-min-ratio'] = (float) (config('trading.volume_filter_min_ratio') ?: 1.0);
                    }
                    try {
                        Artisan::call('strategy:backtest', $params);

                        $parsed = $this->parseBacktestJson(trim(Artisan::output()));
                        if (!$parsed || isset($parsed['error'])) {
                            continue;
                        }

                        $row = [
                            'symbol' => $bot->symbol,
                            'rsi_buy' => $rsiBuy,
                            'rsi_sell' => $rsiSell,
                            'return_percent' => (float) ($parsed['return_percent'] ?? 0),
                            'total_pnl' => (float) ($parsed['total_pnl'] ?? 0),
                            'win_rate' => (float) ($parsed['win_rate'] ?? 0),
                            'total_trades' => (int) ($parsed['total_trades'] ?? 0),
                        ];
                        if ($slTpGrid) {
                            $row['sl'] = $stopLoss;
                            $row['tp'] = $takeProfit;
                        }
                        $allRows[] = $row;
                    } catch (\Throwable $e) {
                        // skip failed run
                    }
                }
            }
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->printTable($allRows);
        $this->printBestPerSymbol($allRows);

        if ($outputFile && !empty($allRows)) {
            file_put_contents($outputFile, json_encode($allRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Результаты сохранены (Results saved): {$outputFile}");
        }

        return self::SUCCESS;
    }

    private function parseBacktestJson(string $output): ?array
    {
        $clean = trim($output);
        $objects = [];
        $depth = 0;
        $current = '';

        for ($i = 0; $i < strlen($clean); $i++) {
            $c = $clean[$i];
            if ($c === '{') {
                if ($depth === 0) {
                    $current = '{';
                } else {
                    $current .= $c;
                }
                $depth++;
            } elseif ($c === '}') {
                $current .= $c;
                $depth--;
                if ($depth === 0) {
                    $objects[] = $current;
                    $current = '';
                }
            } elseif ($depth > 0) {
                $current .= $c;
            }
        }

        foreach ($objects as $raw) {
            $decoded = json_decode($raw, true);
            if ($decoded && isset($decoded['return_percent']) && !isset($decoded['error'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function printTable(array $rows): void
    {
        $hasSlTp = !empty($rows) && isset($rows[0]['sl']);
        $this->line(str_repeat('=', $hasSlTp ? 120 : 100));
        $this->info('ВСЕ РЕЗУЛЬТАТЫ (ALL RESULTS)');
        $this->line(str_repeat('=', $hasSlTp ? 120 : 100));
        if ($hasSlTp) {
            $this->line(sprintf(
                "%-10s | %-8s | %-6s | %-12s | %-12s | %-10s | %-6s",
                'Symbol',
                'RSI B/S',
                'SL/TP',
                'Return (%)',
                'Total PnL',
                'Win Rate %',
                'Trades'
            ));
        } else {
            $this->line(sprintf(
                "%-10s | %-8s | %-12s | %-12s | %-10s | %-6s",
                'Symbol',
                'RSI B/S',
                'Return (%)',
                'Total PnL',
                'Win Rate %',
                'Trades'
            ));
        }
        $this->line(str_repeat('-', $hasSlTp ? 120 : 100));

        foreach ($rows as $r) {
            $pnl = number_format($r['total_pnl'], 2);
            $ret = number_format($r['return_percent'], 2);
            $wr = number_format($r['win_rate'], 1);
            if ($hasSlTp) {
                $slTp = ($r['sl'] ?? '') . '/' . ($r['tp'] ?? '');
                $this->line(sprintf(
                    "%-10s | %d/%-6d | %-6s | %12s | %12s | %10s | %6d",
                    $r['symbol'],
                    $r['rsi_buy'],
                    $r['rsi_sell'],
                    $slTp,
                    $ret,
                    $pnl,
                    $wr,
                    $r['total_trades']
                ));
            } else {
                $this->line(sprintf(
                    "%-10s | %d/%-6d | %12s | %12s | %10s | %6d",
                    $r['symbol'],
                    $r['rsi_buy'],
                    $r['rsi_sell'],
                    $ret,
                    $pnl,
                    $wr,
                    $r['total_trades']
                ));
            }
        }
        $this->line('');
    }

    private function printBestPerSymbol(array $rows): void
    {
        $bySymbol = [];
        foreach ($rows as $r) {
            $s = $r['symbol'];
            if (!isset($bySymbol[$s]) || $r['return_percent'] > $bySymbol[$s]['return_percent']) {
                $bySymbol[$s] = $r;
            }
        }

        $hasSlTp = !empty($rows) && isset($rows[0]['sl']);
        $this->line(str_repeat('=', 80));
        $this->info($hasSlTp ? 'ЛУЧШИЕ RSI + SL/TP ПО СИМВОЛУ (BEST RSI & SL/TP PER SYMBOL)' : 'ЛУЧШИЕ RSI-ПОРОГИ ПО СИМВОЛУ (BEST RSI THRESHOLDS PER SYMBOL)');
        $this->line(str_repeat('=', 80));
        $this->line('');

        foreach ($bySymbol as $symbol => $best) {
            $slTp = isset($best['sl']) ? "  SL/TP {$best['sl']}%/{$best['tp']}%" : '';
            $this->line("  {$symbol}: RSI {$best['rsi_buy']}/{$best['rsi_sell']}{$slTp}  →  Return {$best['return_percent']}%  |  PnL {$best['total_pnl']} USDT  |  Win Rate {$best['win_rate']}%  |  Trades {$best['total_trades']}");
        }

        $this->line('');
        $this->info('Рекомендация: применить указанные RSI (и при --sl-tp-grid — SL/TP) в дашборде по каждому боту.');
        $this->line('Важно: перед реальной торговлей повторить бэктест на более длинном периоде и проверить устойчивость.');
    }
}
