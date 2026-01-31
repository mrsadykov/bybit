<?php

namespace App\Console\Commands;

use App\Models\BotStatistics;
use App\Models\BtcQuoteBot;
use App\Models\BtcQuoteTrade;
use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use App\Models\FuturesTrade;
use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzePerformanceCommand extends Command
{
    protected $signature = 'stats:analyze 
                            {--bot= : Bot ID to analyze (optional, for spot/futures/btc-quote depends on --type)}
                            {--type=all : Type: all|spot|futures|btc-quote}
                            {--days=30 : Number of days to analyze}
                            {--export= : Export to CSV file (optional)}';
    
    protected $description = 'Analyze trading bot performance (spot, futures, BTC-quote)';

    protected float $btcPriceUsdt = 0;

    public function handle(): int
    {
        $botId = $this->option('bot');
        $type = strtolower($this->option('type') ?: 'all');
        $days = (int) $this->option('days');
        $exportPath = $this->option('export');

        if (!in_array($type, ['all', 'spot', 'futures', 'btc-quote'], true)) {
            $this->error('Неверный --type. Допустимо: all, spot, futures, btc-quote');
            return self::FAILURE;
        }

        $this->info("📊 Анализ производительности торговых ботов (Trading bot performance analysis)");
        $this->line("Тип (Type): {$type}");
        $this->line('');

        $startDate = now()->subDays($days);
        $this->line("Период (Period): {$startDate->format('Y-m-d')} - " . now()->format('Y-m-d'));
        $this->line("Дней (Days): {$days}");
        $this->line('');

        if ($type === 'btc-quote' || $type === 'all') {
            $this->btcPriceUsdt = $this->getBtcPriceUsdt();
            if ($this->btcPriceUsdt <= 0 && ($type === 'btc-quote' || BtcQuoteTrade::whereNotNull('realized_pnl_btc')->exists())) {
                $this->warn('Цена BTC не получена; PnL BTC-quote в USDT будет 0');
            }
        }

        $allResults = [];
        $analysisDate = now()->format('Y-m-d');

        // --- SPOT ---
        if ($type === 'all' || $type === 'spot') {
            $bots = $botId ? TradingBot::where('id', $botId)->get() : TradingBot::all();
            $userBotIds = $bots->pluck('id')->toArray();

            if (!$bots->isEmpty()) {
                $this->line(str_repeat('=', 60));
                $this->info("🟢 SPOT БОТЫ (SPOT BOTS)");
                $this->line(str_repeat('-', 60));
                foreach ($bots as $bot) {
                    $this->line("Бот #{$bot->id} | {$bot->symbol}");
                    $stats = $this->calculateBotStatsSpot($bot, $startDate);
                    $allResults[] = array_merge(['type' => 'spot', 'bot_id' => $bot->id, 'symbol' => $bot->symbol], $stats);
                    $this->displayStats($stats);
                    $this->saveStatistics($bot->id, $stats, $analysisDate, $days);
                }
                if ($bots->count() > 1) {
                    $overallSpot = $this->calculateOverallStatsFromResults(array_filter($allResults, fn ($r) => ($r['type'] ?? '') === 'spot'));
                    $this->line("📈 Spot итого: PnL " . number_format($overallSpot['total_pnl'], 8) . " USDT, сделок: {$overallSpot['total_trades']}");
                }
                $this->line('');
            } elseif ($type === 'spot') {
                $this->warn('Spot боты не найдены');
            }
        }

        // --- FUTURES ---
        if ($type === 'all' || $type === 'futures') {
            $futuresBots = $botId ? FuturesBot::where('id', $botId)->get() : FuturesBot::all();
            if (!$futuresBots->isEmpty()) {
                $this->line(str_repeat('=', 60));
                $this->info("🟡 ФЬЮЧЕРСЫ (FUTURES BOTS)");
                $this->line(str_repeat('-', 60));
                foreach ($futuresBots as $bot) {
                    $this->line("Бот #{$bot->id} | {$bot->symbol}");
                    $stats = $this->calculateBotStatsFutures($bot, $startDate);
                    $allResults[] = array_merge(['type' => 'futures', 'bot_id' => $bot->id, 'symbol' => $bot->symbol], $stats);
                    $this->displayStats($stats);
                }
                if ($futuresBots->count() > 1) {
                    $overallFutures = $this->calculateOverallStatsFromResults(array_filter($allResults, fn ($r) => ($r['type'] ?? '') === 'futures'));
                    $this->line("📈 Futures итого: PnL " . number_format($overallFutures['total_pnl'], 8) . " USDT, сделок: {$overallFutures['total_trades']}");
                }
                $this->line('');
            } elseif ($type === 'futures') {
                $this->warn('Фьючерсные боты не найдены');
            }
        }

        // --- BTC-QUOTE ---
        if ($type === 'all' || $type === 'btc-quote') {
            $btcBots = $botId ? BtcQuoteBot::where('id', $botId)->get() : BtcQuoteBot::all();
            if (!$btcBots->isEmpty()) {
                $this->line(str_repeat('=', 60));
                $this->info("₿ BTC-QUOTE БОТЫ (BTC-QUOTE BOTS)");
                $this->line(str_repeat('-', 60));
                foreach ($btcBots as $bot) {
                    $this->line("Бот #{$bot->id} | {$bot->symbol}");
                    $stats = $this->calculateBotStatsBtcQuote($bot, $startDate);
                    $allResults[] = array_merge(['type' => 'btc-quote', 'bot_id' => $bot->id, 'symbol' => $bot->symbol], $stats);
                    $this->displayStatsBtcQuote($stats);
                }
                if ($btcBots->count() > 1) {
                    $overallBtc = $this->calculateOverallStatsFromResults(array_filter($allResults, fn ($r) => ($r['type'] ?? '') === 'btc-quote'));
                    $this->line("📈 BTC-quote итого: PnL " . number_format($overallBtc['total_pnl'], 8) . " USDT (≈ " . number_format($overallBtc['total_pnl_btc'] ?? 0, 8) . " BTC), сделок: {$overallBtc['total_trades']}");
                }
                $this->line('');
            } elseif ($type === 'btc-quote') {
                $this->warn('BTC-quote боты не найдены');
            }
        }

        // Общая сводка по всем типам и сохранение в БД
        if ($type === 'all' && !empty($allResults)) {
            $this->line(str_repeat('=', 60));
            $this->info("📈 ОБЩАЯ СТАТИСТИКА (ALL TYPES COMBINED)");
            $this->line(str_repeat('-', 60));
            $combined = $this->calculateOverallStatsFromResults($allResults);
            $this->displayStats($combined, true);
            $this->saveStatistics(null, $combined, $analysisDate, $days);
        }

        if ($exportPath && !empty($allResults)) {
            $this->exportToCsv($allResults, $exportPath);
            $this->info("✅ Данные экспортированы в: {$exportPath}");
        }

        return self::SUCCESS;
    }

    protected function getBtcPriceUsdt(): float
    {
        $account = ExchangeAccount::where('is_testnet', false)->first();
        if (!$account) {
            return 0;
        }
        try {
            $service = ExchangeServiceFactory::create($account);
            return (float) $service->getPrice('BTCUSDT');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function calculateBotStatsSpot(TradingBot $bot, $startDate): array
    {
        $botId = $bot->id;

        // Закрытые позиции за период
        $closedTrades = Trade::where('trading_bot_id', $botId)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->where('closed_at', '>=', $startDate)
            ->get();

        if ($closedTrades->isEmpty()) {
            return $this->getEmptyStats();
        }

        // Базовые метрики
        $totalTrades = $closedTrades->count();
        $winningTrades = $closedTrades->where('realized_pnl', '>', 0)->count();
        $losingTrades = $closedTrades->where('realized_pnl', '<', 0)->count();
        $totalPnL = $closedTrades->sum('realized_pnl');
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 2) : 0;

        // Средний PnL
        $avgPnL = $totalTrades > 0 ? round($totalPnL / $totalTrades, 8) : 0;
        $avgWin = $winningTrades > 0 
            ? round($closedTrades->where('realized_pnl', '>', 0)->avg('realized_pnl'), 8) 
            : 0;
        $avgLoss = $losingTrades > 0 
            ? round(abs($closedTrades->where('realized_pnl', '<', 0)->avg('realized_pnl')), 8) 
            : 0;

        // Profit Factor
        $totalProfit = $closedTrades->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $totalLoss = abs($closedTrades->where('realized_pnl', '<', 0)->sum('realized_pnl'));
        $profitFactor = $totalLoss > 0 ? round($totalProfit / $totalLoss, 2) : ($totalProfit > 0 ? 999 : 0);

        // Максимальная просадка (Max Drawdown)
        $maxDrawdown = $this->calculateMaxDrawdown($closedTrades);

        // Лучшая/худшая сделка
        $bestTrade = $closedTrades->max('realized_pnl');
        $worstTrade = $closedTrades->min('realized_pnl');

        // Среднее время удержания позиции (в часах)
        $avgHoldTime = $this->calculateAvgHoldTime($botId, $startDate);

        // Количество сделок в день
        $tradesPerDay = $totalTrades > 0 ? round($totalTrades / max(1, now()->diffInDays($startDate)), 2) : 0;

        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl' => round($totalPnL, 8),
            'avg_pnl' => $avgPnL,
            'avg_win' => $avgWin,
            'avg_loss' => $avgLoss,
            'profit_factor' => $profitFactor,
            'max_drawdown' => round($maxDrawdown, 8),
            'best_trade' => round($bestTrade, 8),
            'worst_trade' => round($worstTrade, 8),
            'avg_hold_time_hours' => round($avgHoldTime, 2),
            'trades_per_day' => $tradesPerDay,
        ];
    }

    protected function calculateMaxDrawdown($closedTrades): float
    {
        if ($closedTrades->isEmpty()) {
            return 0;
        }

        // Сортируем по дате закрытия
        $sortedTrades = $closedTrades->sortBy('closed_at');
        
        $cumulativePnL = 0;
        $peak = 0;
        $maxDrawdown = 0;

        foreach ($sortedTrades as $trade) {
            $cumulativePnL += $trade->realized_pnl;
            
            if ($cumulativePnL > $peak) {
                $peak = $cumulativePnL;
            }
            
            $drawdown = $peak - $cumulativePnL;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return $maxDrawdown;
    }

    protected function calculateBotStatsFutures(FuturesBot $bot, $startDate): array
    {
        $closedTrades = FuturesTrade::where('futures_bot_id', $bot->id)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->where('closed_at', '>=', $startDate)
            ->get();

        if ($closedTrades->isEmpty()) {
            return $this->getEmptyStats();
        }

        $totalTrades = $closedTrades->count();
        $winningTrades = $closedTrades->where('realized_pnl', '>', 0)->count();
        $losingTrades = $closedTrades->where('realized_pnl', '<', 0)->count();
        $totalPnL = (float) $closedTrades->sum('realized_pnl');
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 2) : 0;
        $avgPnL = $totalTrades > 0 ? round($totalPnL / $totalTrades, 8) : 0;
        $totalProfit = (float) $closedTrades->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $totalLoss = abs((float) $closedTrades->where('realized_pnl', '<', 0)->sum('realized_pnl'));
        $profitFactor = $totalLoss > 0 ? round($totalProfit / $totalLoss, 2) : ($totalProfit > 0 ? 999 : 0);
        $maxDrawdown = $this->calculateMaxDrawdownFutures($closedTrades);
        $bestTrade = (float) $closedTrades->max('realized_pnl');
        $worstTrade = (float) $closedTrades->min('realized_pnl');
        $tradesPerDay = $totalTrades > 0 ? round($totalTrades / max(1, now()->diffInDays($startDate)), 2) : 0;

        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl' => round($totalPnL, 8),
            'avg_pnl' => $avgPnL,
            'avg_win' => $winningTrades > 0 ? round($closedTrades->where('realized_pnl', '>', 0)->avg('realized_pnl'), 8) : 0,
            'avg_loss' => $losingTrades > 0 ? round(abs($closedTrades->where('realized_pnl', '<', 0)->avg('realized_pnl')), 8) : 0,
            'profit_factor' => $profitFactor,
            'max_drawdown' => round($maxDrawdown, 8),
            'best_trade' => round($bestTrade, 8),
            'worst_trade' => round($worstTrade, 8),
            'avg_hold_time_hours' => 0,
            'trades_per_day' => $tradesPerDay,
        ];
    }

    protected function calculateMaxDrawdownFutures($closedTrades): float
    {
        if ($closedTrades->isEmpty()) {
            return 0;
        }
        $sorted = $closedTrades->sortBy('closed_at');
        $cum = 0;
        $peak = 0;
        $maxDrawdown = 0;
        foreach ($sorted as $t) {
            $cum += (float) $t->realized_pnl;
            if ($cum > $peak) {
                $peak = $cum;
            }
            $dd = $peak - $cum;
            if ($dd > $maxDrawdown) {
                $maxDrawdown = $dd;
            }
        }
        return $maxDrawdown;
    }

    protected function calculateBotStatsBtcQuote(BtcQuoteBot $bot, $startDate): array
    {
        $closedTrades = BtcQuoteTrade::where('btc_quote_bot_id', $bot->id)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl_btc')
            ->where('closed_at', '>=', $startDate)
            ->get();

        if ($closedTrades->isEmpty()) {
            $empty = $this->getEmptyStats();
            $empty['total_pnl_btc'] = 0;
            return $empty;
        }

        $totalPnLBtc = (float) $closedTrades->sum('realized_pnl_btc');
        $totalPnLUsdt = $this->btcPriceUsdt > 0 ? $totalPnLBtc * $this->btcPriceUsdt : 0;
        $totalTrades = $closedTrades->count();
        $winningTrades = $closedTrades->where('realized_pnl_btc', '>', 0)->count();
        $losingTrades = $closedTrades->where('realized_pnl_btc', '<', 0)->count();
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 2) : 0;
        $avgPnL = $totalTrades > 0 ? round($totalPnLUsdt / $totalTrades, 8) : 0;
        $totalProfitBtc = (float) $closedTrades->where('realized_pnl_btc', '>', 0)->sum('realized_pnl_btc');
        $totalLossBtc = abs((float) $closedTrades->where('realized_pnl_btc', '<', 0)->sum('realized_pnl_btc'));
        $totalProfitUsdt = $this->btcPriceUsdt * $totalProfitBtc;
        $totalLossUsdt = $this->btcPriceUsdt * $totalLossBtc;
        $profitFactor = $totalLossUsdt > 0 ? round($totalProfitUsdt / $totalLossUsdt, 2) : ($totalProfitUsdt > 0 ? 999 : 0);
        $maxDrawdown = $this->calculateMaxDrawdownBtcQuote($closedTrades);
        $bestBtc = (float) $closedTrades->max('realized_pnl_btc');
        $worstBtc = (float) $closedTrades->min('realized_pnl_btc');
        $tradesPerDay = $totalTrades > 0 ? round($totalTrades / max(1, now()->diffInDays($startDate)), 2) : 0;

        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl' => round($totalPnLUsdt, 8),
            'total_pnl_btc' => round($totalPnLBtc, 8),
            'avg_pnl' => $avgPnL,
            'avg_win' => 0,
            'avg_loss' => 0,
            'profit_factor' => $profitFactor,
            'max_drawdown' => round($maxDrawdown, 8),
            'best_trade' => round($bestBtc * $this->btcPriceUsdt, 8),
            'worst_trade' => round($worstBtc * $this->btcPriceUsdt, 8),
            'avg_hold_time_hours' => 0,
            'trades_per_day' => $tradesPerDay,
        ];
    }

    protected function calculateMaxDrawdownBtcQuote($closedTrades): float
    {
        if ($closedTrades->isEmpty() || $this->btcPriceUsdt <= 0) {
            return 0;
        }
        $sorted = $closedTrades->sortBy('closed_at');
        $cum = 0;
        $peak = 0;
        $maxDrawdown = 0;
        foreach ($sorted as $t) {
            $cum += (float) $t->realized_pnl_btc * $this->btcPriceUsdt;
            if ($cum > $peak) {
                $peak = $cum;
            }
            $dd = $peak - $cum;
            if ($dd > $maxDrawdown) {
                $maxDrawdown = $dd;
            }
        }
        return $maxDrawdown;
    }

    protected function calculateOverallStatsFromResults(array $results): array
    {
        if (empty($results)) {
            return $this->getEmptyStats();
        }
        $totalTrades = array_sum(array_column($results, 'total_trades'));
        $totalWinning = array_sum(array_column($results, 'winning_trades'));
        $totalPnL = array_sum(array_column($results, 'total_pnl'));
        $totalPnLBtc = array_sum(array_map(fn ($r) => $r['total_pnl_btc'] ?? 0, $results));
        $overallWinRate = $totalTrades > 0 ? round(($totalWinning / $totalTrades) * 100, 2) : 0;
        $avgPnL = $totalTrades > 0 ? round($totalPnL / $totalTrades, 8) : 0;
        $maxDrawdown = !empty($results) ? max(array_column($results, 'max_drawdown')) : 0;
        $out = [
            'total_trades' => $totalTrades,
            'winning_trades' => $totalWinning,
            'losing_trades' => $totalTrades - $totalWinning,
            'win_rate' => $overallWinRate,
            'total_pnl' => round($totalPnL, 8),
            'avg_pnl' => $avgPnL,
            'max_drawdown' => $maxDrawdown,
        ];
        if ($totalPnLBtc != 0) {
            $out['total_pnl_btc'] = round($totalPnLBtc, 8);
        }
        return $out;
    }

    protected function calculateAvgHoldTime(int $botId, $startDate): float
    {
        // Получаем все BUY и соответствующие SELL
        $buyTrades = Trade::where('trading_bot_id', $botId)
            ->where('side', 'BUY')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $startDate)
            ->get();

        if ($buyTrades->isEmpty()) {
            return 0;
        }

        $totalHours = 0;
        $count = 0;

        foreach ($buyTrades as $buy) {
            if ($buy->filled_at && $buy->closed_at) {
                $hours = $buy->filled_at->diffInHours($buy->closed_at);
                $totalHours += $hours;
                $count++;
            }
        }

        return $count > 0 ? $totalHours / $count : 0;
    }

    protected function displayStatsBtcQuote(array $stats): void
    {
        $this->line("📈 PnL: " . number_format($stats['total_pnl'], 8) . " USDT" . (isset($stats['total_pnl_btc']) ? " (≈ " . number_format($stats['total_pnl_btc'], 8) . " BTC)" : ""));
        $this->line("📊 Сделок (Trades): {$stats['total_trades']}");
        $this->line("🎯 Win Rate: {$stats['win_rate']}%");
        if (isset($stats['profit_factor'])) {
            $this->line("💎 Profit Factor: {$stats['profit_factor']}");
        }
        if (isset($stats['max_drawdown'])) {
            $this->line("📉 Макс. просадка (Max Drawdown): " . number_format($stats['max_drawdown'], 8) . " USDT");
        }
    }

    protected function calculateOverallStatsAllTime(array $botIds): array
    {
        if (empty($botIds)) {
            return $this->getEmptyStats();
        }

        // Получаем ВСЕ закрытые позиции (без фильтра по дате)
        $closedTrades = Trade::whereIn('trading_bot_id', $botIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->get();

        if ($closedTrades->isEmpty()) {
            return $this->getEmptyStats();
        }

        // Базовые метрики
        $totalTrades = $closedTrades->count();
        $winningTrades = $closedTrades->where('realized_pnl', '>', 0)->count();
        $losingTrades = $closedTrades->where('realized_pnl', '<', 0)->count();
        $totalPnL = $closedTrades->sum('realized_pnl');
        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 2) : 0;

        // Средний PnL
        $avgPnL = $totalTrades > 0 ? round($totalPnL / $totalTrades, 8) : 0;

        // Profit Factor
        $totalProfit = $closedTrades->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $totalLoss = abs($closedTrades->where('realized_pnl', '<', 0)->sum('realized_pnl'));
        $profitFactor = $totalLoss > 0 ? round($totalProfit / $totalLoss, 2) : ($totalProfit > 0 ? 999 : 0);

        // Максимальная просадка
        $maxDrawdown = $this->calculateMaxDrawdown($closedTrades);

        // Лучшая/худшая сделка
        $bestTrade = $closedTrades->max('realized_pnl');
        $worstTrade = $closedTrades->min('realized_pnl');

        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'total_pnl' => round($totalPnL, 8),
            'avg_pnl' => $avgPnL,
            'avg_win' => $winningTrades > 0 ? round($closedTrades->where('realized_pnl', '>', 0)->avg('realized_pnl'), 8) : 0,
            'avg_loss' => $losingTrades > 0 ? round(abs($closedTrades->where('realized_pnl', '<', 0)->avg('realized_pnl')), 8) : 0,
            'profit_factor' => $profitFactor,
            'max_drawdown' => round($maxDrawdown, 8),
            'best_trade' => round($bestTrade, 8),
            'worst_trade' => round($worstTrade, 8),
            'avg_hold_time_hours' => 0,
            'trades_per_day' => 0,
        ];
    }

    protected function displayStats(array $stats, bool $overall = false): void
    {
        $label = $overall ? 'Общее' : 'Бот';

        $this->line("📈 {$label} PnL (Total PnL): " . number_format($stats['total_pnl'], 8) . " USDT");
        $this->line("📊 Сделок (Trades): {$stats['total_trades']}");
        $this->line("✅ Прибыльных (Winning): {$stats['winning_trades']}");
        $this->line("❌ Убыточных (Losing): {$stats['losing_trades']}");
        $this->line("🎯 Win Rate: {$stats['win_rate']}%");
        
        if (isset($stats['avg_pnl'])) {
            $this->line("📉 Средний PnL (Avg PnL): " . number_format($stats['avg_pnl'], 8) . " USDT");
        }
        
        if (isset($stats['profit_factor'])) {
            $this->line("💎 Profit Factor: {$stats['profit_factor']}");
        }
        
        if (isset($stats['max_drawdown'])) {
            $this->line("📉 Макс. просадка (Max Drawdown): " . number_format($stats['max_drawdown'], 8) . " USDT");
        }
        
        if (isset($stats['best_trade'])) {
            $this->line("⭐ Лучшая сделка (Best Trade): " . number_format($stats['best_trade'], 8) . " USDT");
        }
        
        if (isset($stats['worst_trade'])) {
            $this->line("💥 Худшая сделка (Worst Trade): " . number_format($stats['worst_trade'], 8) . " USDT");
        }
        
        if (isset($stats['trades_per_day'])) {
            $this->line("📅 Сделок в день (Trades/Day): {$stats['trades_per_day']}");
        }
    }

    protected function getEmptyStats(): array
    {
        return [
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => 0,
            'total_pnl' => 0,
            'avg_pnl' => 0,
            'avg_win' => 0,
            'avg_loss' => 0,
            'profit_factor' => 0,
            'max_drawdown' => 0,
            'best_trade' => 0,
            'worst_trade' => 0,
            'avg_hold_time_hours' => 0,
            'trades_per_day' => 0,
        ];
    }

    protected function exportToCsv(array $data, string $path): void
    {
        $file = fopen($path, 'w');
        
        if (!$file) {
            $this->error("Не удалось создать файл (Failed to create file): {$path}");
            return;
        }

        // Заголовки
        if (!empty($data)) {
            fputcsv($file, array_keys($data[0]));
            
            // Данные
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
        }

        fclose($file);
    }

    protected function saveStatistics(?int $botId, array $stats, string $analysisDate, int $days): void
    {
        // Обновляем или создаем запись за сегодня
        BotStatistics::updateOrCreate(
            [
                'trading_bot_id' => $botId,
                'analysis_date' => $analysisDate,
            ],
            [
                'days_period' => $days,
                'total_trades' => $stats['total_trades'],
                'winning_trades' => $stats['winning_trades'],
                'losing_trades' => $stats['losing_trades'],
                'win_rate' => $stats['win_rate'],
                'total_pnl' => $stats['total_pnl'],
                'avg_pnl' => $stats['avg_pnl'] ?? 0,
                'avg_win' => $stats['avg_win'] ?? 0,
                'avg_loss' => $stats['avg_loss'] ?? 0,
                'profit_factor' => $stats['profit_factor'] ?? 0,
                'max_drawdown' => $stats['max_drawdown'] ?? 0,
                'best_trade' => $stats['best_trade'] ?? 0,
                'worst_trade' => $stats['worst_trade'] ?? 0,
                'avg_hold_time_hours' => $stats['avg_hold_time_hours'] ?? 0,
                'trades_per_day' => $stats['trades_per_day'] ?? 0,
            ]
        );
    }
}
