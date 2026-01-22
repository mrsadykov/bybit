<?php

namespace App\Console\Commands;

use App\Models\BotStatistics;
use App\Models\Trade;
use App\Models\TradingBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzePerformanceCommand extends Command
{
    protected $signature = 'stats:analyze 
                            {--bot= : Bot ID to analyze (optional)}
                            {--days=30 : Number of days to analyze}
                            {--export= : Export to CSV file (optional)}';
    
    protected $description = 'Analyze trading bot performance with detailed metrics';

    public function handle(): int
    {
        $botId = $this->option('bot');
        $days = (int) $this->option('days');
        $exportPath = $this->option('export');

        $this->info("📊 Анализ производительности торговых ботов (Trading bot performance analysis)");
        $this->line('');

        // Определяем период анализа
        $startDate = now()->subDays($days);
        $this->line("Период анализа (Analysis period): {$startDate->format('Y-m-d')} - " . now()->format('Y-m-d'));
        $this->line("Дней (Days): {$days}");
        $this->line('');

        // Получаем ботов для анализа
        if ($botId) {
            $bots = TradingBot::where('id', $botId)->get();
            if ($bots->isEmpty()) {
                $this->error("Бот #{$botId} не найден (Bot #{$botId} not found)");
                return self::FAILURE;
            }
            $userBotIds = $bots->pluck('id')->toArray();
        } else {
            $bots = TradingBot::all();
            $userBotIds = $bots->pluck('id')->toArray();
        }

        if ($bots->isEmpty()) {
            $this->warn('Боты не найдены (No bots found)');
            return self::SUCCESS;
        }

        $allResults = [];
        $analysisDate = now()->format('Y-m-d');

        foreach ($bots as $bot) {
            $this->line(str_repeat('=', 60));
            $this->info("Бот #{$bot->id} | {$bot->symbol}");
            $this->line(str_repeat('-', 60));

            $stats = $this->calculateBotStats($bot, $startDate);
            $allResults[] = array_merge(['bot_id' => $bot->id, 'symbol' => $bot->symbol], $stats);

            $this->displayStats($stats);

            // Сохраняем статистику в БД
            $this->saveStatistics($bot->id, $stats, $analysisDate, $days);
            $this->line('');
        }

        // Общая статистика (рассчитываем за ВСЕ время, не только за период)
        if ($bots->count() > 1) {
            $this->line(str_repeat('=', 60));
            $this->info("📈 ОБЩАЯ СТАТИСТИКА (OVERALL STATISTICS)");
            $this->line(str_repeat('-', 60));

            // Рассчитываем общую статистику за ВСЕ время (не только за период)
            $overallStatsAllTime = $this->calculateOverallStatsAllTime($userBotIds ?? []);
            $this->displayStats($overallStatsAllTime, true);

            // Сохраняем общую статистику за период (30 дней)
            $overallStats = $this->calculateOverallStats($allResults);
            $this->saveStatistics(null, $overallStats, $analysisDate, $days);
            
            // Также сохраняем статистику за ВСЕ время (days_period = 0)
            $this->saveStatistics(null, $overallStatsAllTime, $analysisDate, 0);
        }

        // Экспорт в CSV
        if ($exportPath) {
            $this->exportToCsv($allResults, $exportPath);
            $this->info("✅ Данные экспортированы в: {$exportPath}");
        }

        return self::SUCCESS;
    }

    protected function calculateBotStats(TradingBot $bot, $startDate): array
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

    protected function calculateOverallStats(array $allResults): array
    {
        $totalTrades = array_sum(array_column($allResults, 'total_trades'));
        $totalWinning = array_sum(array_column($allResults, 'winning_trades'));
        $totalPnL = array_sum(array_column($allResults, 'total_pnl'));
        
        $overallWinRate = $totalTrades > 0 ? round(($totalWinning / $totalTrades) * 100, 2) : 0;
        $avgPnL = $totalTrades > 0 ? round($totalPnL / $totalTrades, 8) : 0;
        $maxDrawdown = max(array_column($allResults, 'max_drawdown'));

        return [
            'total_trades' => $totalTrades,
            'winning_trades' => $totalWinning,
            'losing_trades' => $totalTrades - $totalWinning,
            'win_rate' => $overallWinRate,
            'total_pnl' => round($totalPnL, 8),
            'avg_pnl' => $avgPnL,
            'max_drawdown' => $maxDrawdown,
        ];
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
