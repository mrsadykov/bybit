<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use Illuminate\Console\Command;

class ApplyOptimizeResultsCommand extends Command
{
    protected $signature = 'strategy:apply-optimize
                            {--dry-run : Показать изменения без записи в БД}';

    protected $description = 'Применить рекомендуемые RSI-пороги по символам (результаты strategy:optimize-all): BTC 45/55, ETH 40/60, SOL 42/58, BNB 38/62';

    /** Рекомендуемые RSI buy/sell по символу (strategy:optimize-all) */
    private const RECOMMENDED = [
        'BTCUSDT' => [45, 55],
        'ETHUSDT' => [40, 60],
        'SOLUSDT' => [42, 58],
        'BNBUSDT' => [38, 62],
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Режим dry-run: изменения не сохраняются (Dry-run: no DB updates)');
            $this->line('');
        }

        $updated = 0;
        foreach (TradingBot::all() as $bot) {
            $key = strtoupper($bot->symbol);
            if (!isset(self::RECOMMENDED[$key])) {
                $this->line("  ⏭️  {$bot->symbol}: нет рекомендации, пропуск (no recommendation, skip)");
                continue;
            }

            [$buy, $sell] = self::RECOMMENDED[$key];
            $changed = (float) ($bot->rsi_buy_threshold ?? -1) !== (float) $buy
                || (float) ($bot->rsi_sell_threshold ?? -1) !== (float) $sell;

            if (!$changed) {
                $this->line("  ✓ {$bot->symbol}: уже {$bot->rsi_buy_threshold}/{$bot->rsi_sell_threshold} (unchanged)");
                continue;
            }

            $this->line("  📝 {$bot->symbol}: " . ($bot->rsi_buy_threshold ?? '—') . '/' . ($bot->rsi_sell_threshold ?? '—') . " → {$buy}/{$sell}");

            if (!$dryRun) {
                $bot->update([
                    'rsi_buy_threshold' => $buy,
                    'rsi_sell_threshold' => $sell,
                ]);
                $updated++;
            }
        }

        $this->line('');
        if ($dryRun) {
            $this->info('Запустите без --dry-run, чтобы применить изменения (Run without --dry-run to apply).');
        } else {
            $this->info("Обновлено ботов (Bots updated): {$updated}");
        }

        return self::SUCCESS;
    }
}
