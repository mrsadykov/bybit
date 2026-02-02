<?php

namespace App\Http\Controllers;

use App\Models\BtcQuoteTrade;
use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use App\Models\FuturesTrade;
use App\Models\Trade;
use App\Models\TradingBot;
use App\Models\BtcQuoteBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradesController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();

        // Получаем все боты пользователя
        $bots = TradingBot::where('user_id', $user->id)->get();
        $userBotIds = $bots->pluck('id');

        // Запрос для сделок
        $query = Trade::whereIn('trading_bot_id', $userBotIds)
            ->with('bot');

        // Фильтры
        if ($request->filled('bot_id')) {
            $query->where('trading_bot_id', $request->bot_id);
        }

        if ($request->filled('symbol')) {
            $query->where('symbol', $request->symbol);
        }

        if ($request->filled('side')) {
            $query->where('side', $request->side);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Сортировка
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $trades = $query->paginate(50)->withQueryString();

        // Статистика для фильтров
        $allTrades = Trade::whereIn('trading_bot_id', $userBotIds)->get();
        $symbols = $allTrades->pluck('symbol')->unique()->sort()->values();
        $statuses = ['PENDING', 'SENT', 'PARTIALLY_FILLED', 'FILLED', 'FAILED'];

        return view('trades.index', compact(
            'trades',
            'bots',
            'symbols',
            'statuses',
            'userBotIds'
        ));
    }

    /**
     * Экспорт сделок (spot, futures, btc-quote) в CSV с учётом фильтров.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();
        $userBotIds = TradingBot::where('user_id', $user->id)->pluck('id');
        $futuresBotIds = FuturesBot::where('user_id', $user->id)->pluck('id');
        $btcQuoteBotIds = BtcQuoteBot::where('user_id', $user->id)->pluck('id');

        $btcPriceUsdt = 0;
        $account = ExchangeAccount::where('user_id', $user->id)->where('is_testnet', false)->first();
        if ($account) {
            try {
                $btcPriceUsdt = (float) ExchangeServiceFactory::create($account)->getPrice('BTCUSDT');
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $filename = 'trades_' . now()->format('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () use ($request, $userBotIds, $futuresBotIds, $btcQuoteBotIds, $btcPriceUsdt) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, ['type', 'date', 'symbol', 'side', 'quantity', 'price', 'status', 'pnl_usdt', 'bot_id'], ';');

            $dateFrom = $request->filled('date_from') ? $request->date_from : null;
            $dateTo = $request->filled('date_to') ? $request->date_to : null;

            // Spot
            $spotQuery = Trade::whereIn('trading_bot_id', $userBotIds)->orderBy('created_at', 'desc');
            if ($dateFrom) {
                $spotQuery->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $spotQuery->whereDate('created_at', '<=', $dateTo);
            }
            if ($request->filled('bot_id')) {
                $spotQuery->where('trading_bot_id', $request->bot_id);
            }
            if ($request->filled('symbol')) {
                $spotQuery->where('symbol', $request->symbol);
            }
            if ($request->filled('side')) {
                $spotQuery->where('side', $request->side);
            }
            if ($request->filled('status')) {
                $spotQuery->where('status', $request->status);
            }
            foreach ($spotQuery->get() as $t) {
                fputcsv($handle, [
                    'spot',
                    $t->created_at->format('Y-m-d H:i:s'),
                    $t->symbol,
                    $t->side,
                    $t->quantity,
                    $t->price,
                    $t->status,
                    $t->realized_pnl ?? '',
                    $t->trading_bot_id,
                ], ';');
            }

            // Futures
            if ($futuresBotIds->isNotEmpty()) {
                $futQuery = FuturesTrade::whereIn('futures_bot_id', $futuresBotIds)->orderBy('created_at', 'desc');
                if ($dateFrom) {
                    $futQuery->whereDate('created_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $futQuery->whereDate('created_at', '<=', $dateTo);
                }
                foreach ($futQuery->get() as $t) {
                    fputcsv($handle, [
                        'futures',
                        $t->created_at->format('Y-m-d H:i:s'),
                        $t->symbol,
                        $t->side,
                        $t->quantity,
                        $t->price,
                        $t->status,
                        $t->realized_pnl ?? '',
                        $t->futures_bot_id,
                    ], ';');
                }
            }

            // BTC-quote
            if ($btcQuoteBotIds->isNotEmpty()) {
                $btcQuery = BtcQuoteTrade::whereIn('btc_quote_bot_id', $btcQuoteBotIds)->orderBy('created_at', 'desc');
                if ($dateFrom) {
                    $btcQuery->whereDate('created_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $btcQuery->whereDate('created_at', '<=', $dateTo);
                }
                foreach ($btcQuery->get() as $t) {
                    $pnlUsdt = $btcPriceUsdt > 0 && $t->realized_pnl_btc !== null
                        ? (float) $t->realized_pnl_btc * $btcPriceUsdt
                        : '';
                    fputcsv($handle, [
                        'btc_quote',
                        $t->created_at->format('Y-m-d H:i:s'),
                        $t->symbol,
                        $t->side,
                        $t->quantity,
                        $t->price,
                        $t->status,
                        $pnlUsdt,
                        $t->btc_quote_bot_id,
                    ], ';');
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
