<?php

namespace App\Console\Commands;

use App\Models\FuturesBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Бэктест всех фьючерсных ботов из БД (OKX perpetual).
 */
class BacktestFuturesAllCommand extends Command
{
    protected $signature = 'strategy:futures-backtest-all
                            {--period=500 : Количество свечей}
                            {--output= : Файл для сохранения результатов (JSON)}';

    protected $description = 'Бэктест всех фьючерсных ботов (OKX perpetual)';

    public function handle(): int
    {
        $period = (int) $this->option('period');
        $outputFile = $this->option('output');

        $bots = FuturesBot::with('exchangeAccount')
            ->get()
            ->filter(fn ($bot) => $bot->exchangeAccount && $bot->exchangeAccount->exchange === 'okx');

        if ($bots->isEmpty()) {
            $this->warn('Фьючерсных ботов не найдено (No futures bots found)');
            return self::FAILURE;
        }

        $this->info('Бэктест фьючерсов для ' . $bots->count() . ' ботов (period=' . $period . ')');
        $this->line('');

        $allResults = [];

        foreach ($bots as $bot) {
            $this->line(str_repeat('-', 60));
            $this->info("Фьючерсный бот #{$bot->id}: {$bot->symbol}");

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
                    $allResults[] = [
                        'bot_id' => $bot->id,
                        'symbol' => $bot->symbol,
                        'timeframe' => $bot->timeframe,
                        'results' => $decoded,
                    ];
                    $this->line('  Return: ' . number_format($decoded['return_percent'], 2) . '% | Trades: ' . ($decoded['total_trades'] ?? 0) . ' | Win Rate: ' . number_format($decoded['win_rate'] ?? 0, 2) . '% | PnL: ' . number_format($decoded['total_pnl'] ?? 0, 2) . ' USDT');
                } else {
                    $this->warn('  Не удалось распарсить результат или ошибка');
                }
            } catch (\Throwable $e) {
                $this->error('  Ошибка: ' . $e->getMessage());
            }
        }

        $this->line('');

        if (!empty($allResults)) {
            usort($allResults, fn ($a, $b) => ($b['results']['return_percent'] ?? 0) <=> ($a['results']['return_percent'] ?? 0));
            $this->info('ТОП по доходности:');
            foreach (array_slice($allResults, 0, 5) as $i => $r) {
                $this->line(sprintf('  %d. %s (%s) — Return: %s%%, PnL: %s USDT', $i + 1, $r['symbol'], $r['timeframe'], number_format($r['results']['return_percent'], 2), number_format($r['results']['total_pnl'] ?? 0, 2)));
            }
            if ($outputFile) {
                file_put_contents($outputFile, json_encode($allResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("Результаты сохранены: {$outputFile}");
            }
        }

        return self::SUCCESS;
    }
}
