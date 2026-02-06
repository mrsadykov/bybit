<?php

namespace App\Console\Commands;

use App\Models\BtcQuoteBot;
use App\Models\TradingBot;
use Illuminate\Console\Command;

class ApplyOptimizeResultsCommand extends Command
{
    protected $signature = 'strategy:apply-optimize
                            {--dry-run : Показать изменения без записи в БД}';

    protected $description = 'Применить RSI-пороги: spot по strategy:optimize-all (BTC/ETH/BNB 38/62, SOL 40/60), btc-quote 40/60';

    /** Рекомендуемые RSI buy/sell по символу spot (по результатам strategy:optimize-all — наименьший убыток) */
    private const RECOMMENDED_SPOT = [
        'BTCUSDT' => [38, 62],
        'ETHUSDT' => [38, 62],
        'SOLUSDT' => [40, 60],
        'BNBUSDT' => [38, 62],
    ];

    /** RSI для всех btc-quote ботов (пары за BTC) */
    private const BTC_QUOTE_RSI = [40, 60];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Режим dry-run: изменения не сохраняются (Dry-run: no DB updates)');
            $this->line('');
        }

        $updated = 0;

        // --- Spot ---
        $this->line('Spot боты (Spot bots):');
        foreach (TradingBot::all() as $bot) {
            $key = strtoupper($bot->symbol);
            if (!isset(self::RECOMMENDED_SPOT[$key])) {
                $this->line("  ⏭️  {$bot->symbol}: нет рекомендации, пропуск (no recommendation, skip)");
                continue;
            }

            [$buy, $sell] = self::RECOMMENDED_SPOT[$key];
            $changed = (float) ($bot->rsi_buy_threshold ?? -1) !== (float) $buy
                || (float) ($bot->rsi_sell_threshold ?? -1) !== (float) $sell;

            if (!$changed) {
                $this->line("  ✓ {$bot->symbol}: уже " . ($bot->rsi_buy_threshold ?? '—') . '/' . ($bot->rsi_sell_threshold ?? '—') . " (unchanged)");
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

        // --- BTC-quote (40/60) ---
        $this->line('');
        $this->line('Пара за BTC (BTC-quote bots), RSI 40/60:');
        [$btcBuy, $btcSell] = self::BTC_QUOTE_RSI;
        foreach (BtcQuoteBot::all() as $bot) {
            $changed = (float) ($bot->rsi_buy_threshold ?? -1) !== (float) $btcBuy
                || (float) ($bot->rsi_sell_threshold ?? -1) !== (float) $btcSell;

            if (!$changed) {
                $this->line("  ✓ {$bot->symbol}: уже " . ($bot->rsi_buy_threshold ?? '—') . '/' . ($bot->rsi_sell_threshold ?? '—') . " (unchanged)");
                continue;
            }

            $this->line("  📝 {$bot->symbol}: " . ($bot->rsi_buy_threshold ?? '—') . '/' . ($bot->rsi_sell_threshold ?? '—') . " → {$btcBuy}/{$btcSell}");

            if (!$dryRun) {
                $bot->update([
                    'rsi_buy_threshold' => $btcBuy,
                    'rsi_sell_threshold' => $btcSell,
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
