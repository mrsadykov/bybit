<?php

namespace App\Http\Controllers;

use App\Models\BotStatistics;
use App\Models\BtcQuoteBot;
use App\Models\BtcQuoteTrade;
use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use App\Models\FuturesTrade;
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

        // Spot боты
        $bots = TradingBot::where('user_id', $user->id)
            ->with(['exchangeAccount', 'trades' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        // Фьючерсные и BTC-quote боты
        $futuresBots = FuturesBot::where('user_id', $user->id)->get();
        $btcQuoteBots = BtcQuoteBot::where('user_id', $user->id)->get();

        $userBotIds = $bots->pluck('id');
        $futuresBotIds = $futuresBots->pluck('id');
        $btcQuoteBotIds = $btcQuoteBots->pluck('id');

        // Сводка по всем ботам
        $totalBots = $bots->count() + $futuresBots->count() + $btcQuoteBots->count();
        $activeBots = $bots->where('is_active', true)->count()
            + $futuresBots->where('is_active', true)->count()
            + $btcQuoteBots->where('is_active', true)->count();
        $dryRunBots = $bots->where('dry_run', true)->count()
            + $futuresBots->where('dry_run', true)->count()
            + $btcQuoteBots->where('dry_run', true)->count();

        // Цена BTC для перевода PnL BTC-quote в USDT
        $btcPriceUsdt = $this->getBtcPriceUsdt($user);

        // Сделки: spot
        $allSpotTrades = Trade::whereIn('trading_bot_id', $userBotIds)->get();
        $closedSpot = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->get();

        // Сделки: фьючерсы
        $allFuturesTrades = $futuresBotIds->isEmpty() ? collect() : FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)->get();
        $closedFutures = $futuresBotIds->isEmpty() ? collect() : FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->get();

        // Сделки: BTC-quote
        $allBtcQuoteTrades = $btcQuoteBotIds->isEmpty() ? collect() : BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)->get();
        $closedBtcQuote = $btcQuoteBotIds->isEmpty() ? collect() : BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl_btc')
            ->get();

        $totalTrades = $allSpotTrades->count() + $allFuturesTrades->count() + $allBtcQuoteTrades->count();
        $filledTrades = $allSpotTrades->where('status', 'FILLED')->count()
            + $allFuturesTrades->where('status', 'FILLED')->count()
            + $allBtcQuoteTrades->where('status', 'FILLED')->count();

        // PnL в USDT (spot + futures + btc-quote в эквиваленте)
        $totalPnLSpot = $closedSpot->sum('realized_pnl');
        $totalPnLFutures = $closedFutures->sum('realized_pnl');
        $totalPnLBtcQuoteBtc = $closedBtcQuote->sum('realized_pnl_btc');
        $totalPnLBtcQuoteUsdt = $btcPriceUsdt > 0 ? (float) $totalPnLBtcQuoteBtc * $btcPriceUsdt : 0;
        $totalPnL = (float) $totalPnLSpot + (float) $totalPnLFutures + $totalPnLBtcQuoteUsdt;

        $winningTrades = $closedSpot->where('realized_pnl', '>', 0)->count()
            + $closedFutures->where('realized_pnl', '>', 0)->count()
            + $closedBtcQuote->where('realized_pnl_btc', '>', 0)->count();
        $losingTrades = $closedSpot->where('realized_pnl', '<', 0)->count()
            + $closedFutures->where('realized_pnl', '<', 0)->count()
            + $closedBtcQuote->where('realized_pnl_btc', '<', 0)->count();
        $closedPositionsCount = $closedSpot->count() + $closedFutures->count() + $closedBtcQuote->count();
        $winRate = $closedPositionsCount > 0
            ? round(($winningTrades / $closedPositionsCount) * 100, 2)
            : 0;

        // Расширенные метрики (по объединённым закрытым сделкам)
        $avgPnL = $closedPositionsCount > 0 ? round($totalPnL / $closedPositionsCount, 8) : 0;
        $totalProfit = (float) $closedSpot->where('realized_pnl', '>', 0)->sum('realized_pnl')
            + (float) $closedFutures->where('realized_pnl', '>', 0)->sum('realized_pnl')
            + ($totalPnLBtcQuoteBtc > 0 ? (float) $totalPnLBtcQuoteBtc * $btcPriceUsdt : 0);
        $totalLossBtcQuote = $totalPnLBtcQuoteBtc < 0 ? abs((float) $totalPnLBtcQuoteBtc * $btcPriceUsdt) : 0;
        $totalLoss = abs((float) $closedSpot->where('realized_pnl', '<', 0)->sum('realized_pnl'))
            + abs((float) $closedFutures->where('realized_pnl', '<', 0)->sum('realized_pnl'))
            + $totalLossBtcQuote;
        $profitFactor = $totalLoss > 0
            ? round($totalProfit / $totalLoss, 2)
            : ($totalProfit > 0 ? 999 : 0);

        $combinedClosedForDrawdown = $this->buildCombinedClosedPositions($closedSpot, $closedFutures, $closedBtcQuote, $btcPriceUsdt);
        $maxDrawdown = $this->calculateMaxDrawdownCombined($combinedClosedForDrawdown);
        $bestTrade = $combinedClosedForDrawdown->isEmpty() ? 0 : $combinedClosedForDrawdown->max('pnl_usdt');
        $worstTrade = $combinedClosedForDrawdown->isEmpty() ? 0 : $combinedClosedForDrawdown->min('pnl_usdt');

        // Открытые позиции: spot, futures, btc-quote (для таблицы)
        $openPositionsSpot = Trade::whereIn('trading_bot_id', $userBotIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with('bot')
            ->get();
        $openPositionsFutures = $futuresBotIds->isEmpty() ? collect() : FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with('futuresBot')
            ->get();
        $openPositionsBtcQuote = $btcQuoteBotIds->isEmpty() ? collect() : BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->with('btcQuoteBot')
            ->get();
        $openPositions = $this->buildOpenPositionsList($openPositionsSpot, $openPositionsFutures, $openPositionsBtcQuote);

        // Данные для графиков (все типы ботов, 30 дней)
        $chartPnlByDay = $this->getPnlByDayCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, $btcPriceUsdt, 30);
        $chartTradesByDay = $this->getTradesCountByDayCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, 30);
        $chartCumulativePnL = $this->getCumulativePnLCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, $btcPriceUsdt, 30);

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
            'totalPnLBtcQuoteBtc',
            'totalPnLBtcQuoteUsdt',
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

    protected function getBtcPriceUsdt($user): float
    {
        $account = ExchangeAccount::where('user_id', $user->id)->where('is_testnet', false)->first();
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

    /** Объединённый список закрытых позиций с pnl_usdt и closed_at для расчёта просадки/лучшей/худшей. */
    protected function buildCombinedClosedPositions($closedSpot, $closedFutures, $closedBtcQuote, float $btcPriceUsdt): \Illuminate\Support\Collection
    {
        $items = collect();
        foreach ($closedSpot as $t) {
            $items->push(['closed_at' => $t->closed_at, 'pnl_usdt' => (float) $t->realized_pnl]);
        }
        foreach ($closedFutures as $t) {
            $items->push(['closed_at' => $t->closed_at, 'pnl_usdt' => (float) $t->realized_pnl]);
        }
        foreach ($closedBtcQuote as $t) {
            $pnlUsdt = $btcPriceUsdt > 0 ? (float) $t->realized_pnl_btc * $btcPriceUsdt : 0;
            $items->push(['closed_at' => $t->closed_at, 'pnl_usdt' => $pnlUsdt]);
        }
        return $items->sortBy('closed_at')->values();
    }

    protected function calculateMaxDrawdownCombined(\Illuminate\Support\Collection $combined): float
    {
        if ($combined->isEmpty()) {
            return 0;
        }
        $cumulativePnL = 0;
        $peak = 0;
        $maxDrawdown = 0;
        foreach ($combined as $item) {
            $cumulativePnL += $item['pnl_usdt'];
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

    /** Единый список открытых позиций с полями: type, symbol, quantity, price, bot_id, bot_label. */
    protected function buildOpenPositionsList($openSpot, $openFutures, $openBtcQuote): \Illuminate\Support\Collection
    {
        $list = collect();
        foreach ($openSpot as $t) {
            $list->push([
                'type' => 'spot',
                'symbol' => $t->symbol,
                'quantity' => $t->quantity,
                'price' => $t->price,
                'bot_id' => $t->bot->id ?? null,
                'bot_label' => 'Spot #' . ($t->bot->id ?? '-'),
            ]);
        }
        foreach ($openFutures as $t) {
            $list->push([
                'type' => 'futures',
                'symbol' => $t->symbol,
                'quantity' => $t->quantity,
                'price' => $t->price,
                'bot_id' => $t->futuresBot->id ?? null,
                'bot_label' => 'Futures #' . ($t->futuresBot->id ?? '-'),
            ]);
        }
        foreach ($openBtcQuote as $t) {
            $list->push([
                'type' => 'btc_quote',
                'symbol' => $t->symbol,
                'quantity' => $t->quantity,
                'price' => $t->price,
                'bot_id' => $t->btcQuoteBot->id ?? null,
                'bot_label' => 'BTC-quote #' . ($t->btcQuoteBot->id ?? '-'),
            ]);
        }
        return $list;
    }

    protected function getPnlByDayCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, float $btcPriceUsdt, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $byDay = [];
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $byDay[$date] = 0;
        }
        $closedSpot = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')->whereNotNull('realized_pnl')->where('closed_at', '>=', $from)->get();
        foreach ($closedSpot as $t) {
            $d = $t->closed_at->format('Y-m-d');
            if (isset($byDay[$d])) {
                $byDay[$d] += (float) $t->realized_pnl;
            }
        }
        if ($futuresBotIds->isNotEmpty()) {
            $closedFutures = FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)
                ->whereNotNull('closed_at')->whereNotNull('realized_pnl')->where('closed_at', '>=', $from)->get();
            foreach ($closedFutures as $t) {
                $d = $t->closed_at->format('Y-m-d');
                if (isset($byDay[$d])) {
                    $byDay[$d] += (float) $t->realized_pnl;
                }
            }
        }
        if ($btcQuoteBotIds->isNotEmpty() && $btcPriceUsdt > 0) {
            $closedBtc = BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)
                ->whereNotNull('closed_at')->whereNotNull('realized_pnl_btc')->where('closed_at', '>=', $from)->get();
            foreach ($closedBtc as $t) {
                $d = $t->closed_at->format('Y-m-d');
                if (isset($byDay[$d])) {
                    $byDay[$d] += (float) $t->realized_pnl_btc * $btcPriceUsdt;
                }
            }
        }
        return ['labels' => array_keys($byDay), 'data' => array_values($byDay)];
    }

    protected function getTradesCountByDayCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $byDay = [];
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $byDay[$date] = 0;
        }
        $closedSpot = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')->where('closed_at', '>=', $from)->get();
        foreach ($closedSpot as $t) {
            $d = $t->closed_at->format('Y-m-d');
            if (isset($byDay[$d])) {
                $byDay[$d]++;
            }
        }
        if ($futuresBotIds->isNotEmpty()) {
            $closedFutures = FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)
                ->whereNotNull('closed_at')->where('closed_at', '>=', $from)->get();
            foreach ($closedFutures as $t) {
                $d = $t->closed_at->format('Y-m-d');
                if (isset($byDay[$d])) {
                    $byDay[$d]++;
                }
            }
        }
        if ($btcQuoteBotIds->isNotEmpty()) {
            $closedBtc = BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)
                ->whereNotNull('closed_at')->where('closed_at', '>=', $from)->get();
            foreach ($closedBtc as $t) {
                $d = $t->closed_at->format('Y-m-d');
                if (isset($byDay[$d])) {
                    $byDay[$d]++;
                }
            }
        }
        return ['labels' => array_keys($byDay), 'data' => array_values($byDay)];
    }

    protected function getCumulativePnLCombined($userBotIds, $futuresBotIds, $btcQuoteBotIds, float $btcPriceUsdt, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $labels = [];
        $data = [];
        $cum = 0;
        $closedSpot = Trade::whereIn('trading_bot_id', $userBotIds)
            ->whereNotNull('closed_at')->whereNotNull('realized_pnl')->where('closed_at', '>=', $from)->orderBy('closed_at')->get();
        $closedFutures = $futuresBotIds->isNotEmpty()
            ? FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)
                ->whereNotNull('closed_at')->whereNotNull('realized_pnl')->where('closed_at', '>=', $from)->orderBy('closed_at')->get()
            : collect();
        $closedBtc = $btcQuoteBotIds->isNotEmpty()
            ? BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)
                ->whereNotNull('closed_at')->whereNotNull('realized_pnl_btc')->where('closed_at', '>=', $from)->orderBy('closed_at')->get()
            : collect();
        $allClosed = collect();
        foreach ($closedSpot as $t) {
            $allClosed->push(['closed_at' => $t->closed_at, 'pnl_usdt' => (float) $t->realized_pnl]);
        }
        foreach ($closedFutures as $t) {
            $allClosed->push(['closed_at' => $t->closed_at, 'pnl_usdt' => (float) $t->realized_pnl]);
        }
        foreach ($closedBtc as $t) {
            $allClosed->push(['closed_at' => $t->closed_at, 'pnl_usdt' => (float) $t->realized_pnl_btc * $btcPriceUsdt]);
        }
        $allClosed = $allClosed->sortBy('closed_at')->values();
        foreach (range(0, $days - 1) as $i) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $labels[] = $date;
            $dayPnL = $allClosed->filter(fn ($item) => $item['closed_at']->format('Y-m-d') === $date)->sum('pnl_usdt');
            $cum += (float) $dayPnL;
            $data[] = round($cum, 4);
        }
        return ['labels' => $labels, 'data' => $data];
    }

}
