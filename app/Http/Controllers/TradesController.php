<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\TradingBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

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
}
