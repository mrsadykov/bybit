<?php

namespace App\Http\Controllers;

use App\Models\BotStatistics;
use App\Models\Trade;
use App\Models\TradingBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        // Получаем все боты пользователя
        $bots = TradingBot::where('user_id', $user->id)
            ->with(['exchangeAccount', 'trades' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        // Статистика по ботам
        $totalBots = $bots->count();
        $activeBots = $bots->where('is_active', true)->count();
        $dryRunBots = $bots->where('dry_run', true)->count();

        // Получаем все сделки пользователя
        $userBotIds = $bots->pluck('id');
        
        // Общая статистика по сделкам
        $allTrades = Trade::whereIn('trading_bot_id', $userBotIds)->get();
        
        $totalTrades = $allTrades->count();
        $filledTrades = $allTrades->where('status', 'FILLED')->count();
        
        // Статистика по закрытым позициям
        $closedPositions = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->get();
        
        $totalPnL = $closedPositions->sum('realized_pnl');
        $winningTrades = $closedPositions->where('realized_pnl', '>', 0)->count();
        $losingTrades = $closedPositions->where('realized_pnl', '<', 0)->count();
        $winRate = $closedPositions->count() > 0 
            ? round(($winningTrades / $closedPositions->count()) * 100, 2) 
            : 0;

        // Расширенные метрики
        $avgPnL = $closedPositions->count() > 0 
            ? round($totalPnL / $closedPositions->count(), 8) 
            : 0;
        
        $totalProfit = $closedPositions->where('realized_pnl', '>', 0)->sum('realized_pnl');
        $totalLoss = abs($closedPositions->where('realized_pnl', '<', 0)->sum('realized_pnl'));
        $profitFactor = $totalLoss > 0 
            ? round($totalProfit / $totalLoss, 2) 
            : ($totalProfit > 0 ? 999 : 0);
        
        // Максимальная просадка
        $maxDrawdown = $this->calculateMaxDrawdown($closedPositions);
        
        // Лучшая/худшая сделка
        $bestTrade = $closedPositions->count() > 0 ? $closedPositions->max('realized_pnl') : 0;
        $worstTrade = $closedPositions->count() > 0 ? $closedPositions->min('realized_pnl') : 0;

        // Последние сделки
        $recentTrades = Trade::whereIn('trading_bot_id', $userBotIds)
            ->with('bot')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Открытые позиции (BUY без закрытия)
        $openPositions = Trade::whereIn('trading_bot_id', $userBotIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with('bot')
            ->get();

        // Статистика по дням (для графика PnL)
        $dailyPnL = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->selectRaw('DATE(closed_at) as date, SUM(realized_pnl) as pnl')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'pnl' => (float) $item->pnl,
                ];
            });

        $closedPositionsCount = $closedPositions->count();

        // Получаем сохраненную статистику из БД (за последние 30 дней)
        $today = now()->format('Y-m-d');
        $savedStats = BotStatistics::where('trading_bot_id', null) // Общая статистика
            ->where('analysis_date', $today)
            ->where('days_period', 30)
            ->first();

        // Статистика по каждому боту
        $botStats = [];
        foreach ($bots as $bot) {
            $botStat = BotStatistics::where('trading_bot_id', $bot->id)
                ->where('analysis_date', $today)
                ->where('days_period', 30)
                ->first();
            
            if ($botStat) {
                $botStats[$bot->id] = $botStat;
            }
        }
        
        return view('dashboard', compact(
            'bots',
            'totalBots',
            'activeBots',
            'dryRunBots',
            'totalTrades',
            'filledTrades',
            'totalPnL',
            'winningTrades',
            'losingTrades',
            'winRate',
            'recentTrades',
            'openPositions',
            'dailyPnL',
            'closedPositionsCount',
            'avgPnL',
            'profitFactor',
            'maxDrawdown',
            'bestTrade',
            'worstTrade',
            'savedStats',
            'botStats'
        ));
    }

    protected function calculateMaxDrawdown($closedPositions): float
    {
        if ($closedPositions->isEmpty()) {
            return 0;
        }

        // Сортируем по дате закрытия
        $sortedTrades = $closedPositions->sortBy('closed_at');
        
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
}
