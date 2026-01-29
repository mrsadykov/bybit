<?php

namespace App\Http\Controllers;

use App\Models\BotStatistics;
use App\Models\ExchangeAccount;
use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
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

        // Открытые позиции (BUY без закрытия)
        $openPositions = Trade::whereIn('trading_bot_id', $userBotIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with('bot')
            ->get();

        $closedPositionsCount = $closedPositions->count();

        // Данные для графиков (последние 30 дней)
        $chartPnlByDay = $this->getPnlByDay($userBotIds, 30);
        $chartTradesByDay = $this->getTradesCountByDay($userBotIds, 30);
        $chartCumulativePnL = $this->getCumulativePnL($userBotIds, 30);

        // Получаем сохраненную статистику из БД
        // Приоритет: сначала за все время (days_period = 0), потом за 30 дней
        $today = now()->format('Y-m-d');
        $savedStats = BotStatistics::where('trading_bot_id', null) // Общая статистика
            ->where('analysis_date', $today)
            ->where(function($query) {
                $query->where('days_period', 0)  // За все время
                      ->orWhere('days_period', 30); // За 30 дней
            })
            ->orderByRaw('CASE WHEN days_period = 0 THEN 0 ELSE 1 END') // Сначала 0 (все время), потом 30
            ->first();

        // Получаем балансы со всех аккаунтов пользователя
        $accountBalances = [];
        $totalBalanceUsdt = 0;
        
        try {
            $accounts = ExchangeAccount::where('user_id', $user->id)
                ->where('is_testnet', false)
                ->get();
            
            foreach ($accounts as $account) {
                try {
                    $exchangeService = ExchangeServiceFactory::create($account);
                    
                    if (method_exists($exchangeService, 'getAllBalances')) {
                        $balances = $exchangeService->getAllBalances();
                        
                        // Получаем цену BTC для конвертации
                        try {
                            $btcPrice = $exchangeService->getPrice('BTCUSDT');
                        } catch (\Throwable $e) {
                            $btcPrice = 0;
                        }
                        
                        // Конвертируем все балансы в USDT
                        $accountTotalUsdt = 0;
                        foreach ($balances as $coin => $amount) {
                            if ($coin === 'USDT') {
                                $accountTotalUsdt += $amount;
                            } elseif ($coin === 'BTC' && $btcPrice > 0) {
                                $accountTotalUsdt += $amount * $btcPrice;
                            } else {
                                // Для других монет пытаемся получить цену
                                try {
                                    $symbol = $coin . 'USDT';
                                    $price = $exchangeService->getPrice($symbol);
                                    $accountTotalUsdt += $amount * $price;
                                } catch (\Throwable $e) {
                                    // Игнорируем монеты, для которых нет пары USDT
                                }
                            }
                        }
                        
                        $accountBalances[] = [
                            'exchange' => strtoupper($account->exchange),
                            'balances' => $balances,
                            'total_usdt' => $accountTotalUsdt,
                        ];
                        
                        $totalBalanceUsdt += $accountTotalUsdt;
                    }
                } catch (\Throwable $e) {
                    // Игнорируем ошибки получения баланса
                    logger()->warning('Failed to get balance for account', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Игнорируем ошибки
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
            'openPositions',
            'closedPositionsCount',
            'avgPnL',
            'profitFactor',
            'maxDrawdown',
            'bestTrade',
            'worstTrade',
            'savedStats',
            'accountBalances',
            'totalBalanceUsdt',
            'chartPnlByDay',
            'chartTradesByDay',
            'chartCumulativePnL'
        ));
    }

    /**
     * PnL по дням за последние N дней (по closed_at).
     */
    protected function getPnlByDay(\Illuminate\Support\Collection $userBotIds, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $closed = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->where('closed_at', '>=', $from)
            ->get();
        $byDay = [];
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $byDay[$date] = 0;
        }
        foreach ($closed as $trade) {
            $d = $trade->closed_at->format('Y-m-d');
            if (isset($byDay[$d])) {
                $byDay[$d] += (float) $trade->realized_pnl;
            }
        }
        return [
            'labels' => array_keys($byDay),
            'data' => array_values($byDay),
        ];
    }

    /**
     * Количество сделок (закрытых) по дням за последние N дней.
     */
    protected function getTradesCountByDay(\Illuminate\Support\Collection $userBotIds, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $closed = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $from)
            ->get();
        $byDay = [];
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $byDay[$date] = 0;
        }
        foreach ($closed as $trade) {
            $d = $trade->closed_at->format('Y-m-d');
            if (isset($byDay[$d])) {
                $byDay[$d]++;
            }
        }
        return [
            'labels' => array_keys($byDay),
            'data' => array_values($byDay),
        ];
    }

    /**
     * Кумулятивный PnL по дням (кривая эквити) за последние N дней.
     */
    protected function getCumulativePnL(\Illuminate\Support\Collection $userBotIds, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $closed = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->where('closed_at', '>=', $from)
            ->orderBy('closed_at')
            ->get();
        $labels = [];
        $data = [];
        $cum = 0;
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $labels[] = $date;
            $dayPnL = $closed->filter(fn ($t) => $t->closed_at->format('Y-m-d') === $date)->sum('realized_pnl');
            $cum += (float) $dayPnL;
            $data[] = round($cum, 4);
        }
        return [
            'labels' => $labels,
            'data' => $data,
        ];
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
