<?php

namespace App\Http\Controllers;

use App\Models\BotDecisionLog;
use App\Models\BtcQuoteBot;
use App\Models\FuturesBot;
use App\Models\TradingBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BotDecisionLogController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $spotIds = TradingBot::where('user_id', $user->id)->pluck('id')->toArray();
        $futuresIds = FuturesBot::where('user_id', $user->id)->pluck('id')->toArray();
        $btcQuoteIds = BtcQuoteBot::where('user_id', $user->id)->pluck('id')->toArray();

        $query = BotDecisionLog::query()
            ->where(function ($q) use ($spotIds, $futuresIds, $btcQuoteIds) {
                $q->where(function ($q2) use ($spotIds) {
                    $q2->where('bot_type', 'spot')->whereIn('bot_id', $spotIds);
                })
                    ->orWhere(function ($q2) use ($futuresIds) {
                        $q2->where('bot_type', 'futures')->whereIn('bot_id', $futuresIds);
                    })
                    ->orWhere(function ($q2) use ($btcQuoteIds) {
                        $q2->where('bot_type', 'btc_quote')->whereIn('bot_id', $btcQuoteIds);
                    });
            });

        if ($request->filled('bot')) {
            $parts = explode(':', $request->bot, 2);
            if (count($parts) === 2) {
                $query->where('bot_type', $parts[0])->where('bot_id', $parts[1]);
            }
        } else {
            if ($request->filled('bot_type')) {
                $query->where('bot_type', $request->bot_type);
            }
            if ($request->filled('bot_id')) {
                $query->where('bot_id', $request->bot_id);
            }
        }
        if ($request->filled('symbol')) {
            $query->where('symbol', $request->symbol);
        }
        if ($request->filled('signal')) {
            $query->where('signal', $request->signal);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $query->orderByDesc('created_at');
        $logs = $query->paginate(50)->withQueryString();

        $symbols = BotDecisionLog::query()
            ->where(function ($q) use ($spotIds, $futuresIds, $btcQuoteIds) {
                $q->where('bot_type', 'spot')->whereIn('bot_id', $spotIds)
                    ->orWhere('bot_type', 'futures')->whereIn('bot_id', $futuresIds)
                    ->orWhere('bot_type', 'btc_quote')->whereIn('bot_id', $btcQuoteIds);
            })
            ->distinct()
            ->pluck('symbol')
            ->sort()
            ->values();

        $spotBots = TradingBot::where('user_id', $user->id)->orderBy('symbol')->get(['id', 'symbol']);
        $futuresBots = FuturesBot::where('user_id', $user->id)->orderBy('symbol')->get(['id', 'symbol']);
        $btcQuoteBots = BtcQuoteBot::where('user_id', $user->id)->orderBy('symbol')->get(['id', 'symbol']);

        return view('decision-log.index', compact(
            'logs',
            'symbols',
            'spotBots',
            'futuresBots',
            'btcQuoteBots'
        ));
    }
}
