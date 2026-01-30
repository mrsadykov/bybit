<?php

namespace App\Http\Controllers;

use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FuturesBotController extends Controller
{
    public function index()
    {
        $bots = FuturesBot::where('user_id', Auth::id())
            ->with(['exchangeAccount', 'futuresTrades' => fn ($q) => $q->latest()->limit(5)])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('futures-bots.index', compact('bots'));
    }

    public function create()
    {
        $accounts = ExchangeAccount::where('user_id', Auth::id())->where('exchange', 'okx')->get();
        return view('futures-bots.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}USDT$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size_usdt' => ['required', 'numeric', 'min:1'],
            'leverage' => ['required', 'integer', 'min:1', 'max:10'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rsi_period' => ['nullable', 'integer', 'min:2', 'max:100'],
            'ema_period' => ['nullable', 'integer', 'min:2', 'max:200'],
            'rsi_buy_threshold' => ['nullable', 'numeric', 'min:20', 'max:80'],
            'rsi_sell_threshold' => ['nullable', 'numeric', 'min:20', 'max:80'],
            'dry_run' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $buy = isset($validated['rsi_buy_threshold']) && $validated['rsi_buy_threshold'] !== '' ? (float) $validated['rsi_buy_threshold'] : null;
        $sell = isset($validated['rsi_sell_threshold']) && $validated['rsi_sell_threshold'] !== '' ? (float) $validated['rsi_sell_threshold'] : null;
        if ($buy !== null && $sell !== null && $buy >= $sell) {
            return back()->withErrors(['rsi_sell_threshold' => __('futures.rsi_sell_must_exceed_buy')])->withInput();
        }

        $account = ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->where('exchange', 'okx')
            ->firstOrFail();

        FuturesBot::create([
            'user_id' => Auth::id(),
            'exchange_account_id' => $account->id,
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'position_size_usdt' => $validated['position_size_usdt'],
            'leverage' => (int) $validated['leverage'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'rsi_period' => $validated['rsi_period'] ?? 17,
            'ema_period' => $validated['ema_period'] ?? 10,
            'rsi_buy_threshold' => $buy,
            'rsi_sell_threshold' => $sell,
            'is_active' => $validated['is_active'] ?? false,
            'dry_run' => $validated['dry_run'] ?? true,
        ]);

        return redirect()->route('futures-bots.index')->with('success', __('futures.bot_created'));
    }

    public function show(FuturesBot $futures_bot)
    {
        if ($futures_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $futures_bot->load(['exchangeAccount', 'futuresTrades' => fn ($q) => $q->latest()->limit(50)]);
        $trades = $futures_bot->futuresTrades;
        $closedWithPnl = $trades->filter(fn ($t) => $t->realized_pnl !== null);
        $stats = [
            'total_trades' => $futures_bot->futuresTrades()->count(),
            'total_pnl' => (float) $futures_bot->futuresTrades()->whereNotNull('realized_pnl')->sum('realized_pnl'),
            'winning_trades' => $closedWithPnl->where('realized_pnl', '>', 0)->count(),
            'losing_trades' => $closedWithPnl->where('realized_pnl', '<', 0)->count(),
            'win_rate' => $closedWithPnl->count() > 0
                ? round(($closedWithPnl->where('realized_pnl', '>', 0)->count() / $closedWithPnl->count()) * 100, 2)
                : 0,
        ];
        return view('futures-bots.show', ['bot' => $futures_bot, 'trades' => $trades, 'stats' => $stats]);
    }

    public function edit(FuturesBot $futures_bot)
    {
        if ($futures_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $accounts = ExchangeAccount::where('user_id', Auth::id())->where('exchange', 'okx')->get();
        return view('futures-bots.edit', ['bot' => $futures_bot, 'accounts' => $accounts]);
    }

    public function update(Request $request, FuturesBot $futures_bot)
    {
        if ($futures_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}USDT$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size_usdt' => ['required', 'numeric', 'min:1'],
            'leverage' => ['required', 'integer', 'min:1', 'max:10'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rsi_period' => ['nullable', 'integer', 'min:2', 'max:100'],
            'ema_period' => ['nullable', 'integer', 'min:2', 'max:200'],
            'rsi_buy_threshold' => ['nullable', 'numeric', 'min:20', 'max:80'],
            'rsi_sell_threshold' => ['nullable', 'numeric', 'min:20', 'max:80'],
            'dry_run' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $buy = isset($validated['rsi_buy_threshold']) && $validated['rsi_buy_threshold'] !== '' ? (float) $validated['rsi_buy_threshold'] : null;
        $sell = isset($validated['rsi_sell_threshold']) && $validated['rsi_sell_threshold'] !== '' ? (float) $validated['rsi_sell_threshold'] : null;
        if ($buy !== null && $sell !== null && $buy >= $sell) {
            return back()->withErrors(['rsi_sell_threshold' => __('futures.rsi_sell_must_exceed_buy')])->withInput();
        }

        ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->where('exchange', 'okx')
            ->firstOrFail();

        $futures_bot->update([
            'exchange_account_id' => $validated['exchange_account_id'],
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'position_size_usdt' => $validated['position_size_usdt'],
            'leverage' => (int) $validated['leverage'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'rsi_period' => $validated['rsi_period'] ?? 17,
            'ema_period' => $validated['ema_period'] ?? 10,
            'rsi_buy_threshold' => $buy,
            'rsi_sell_threshold' => $sell,
            'dry_run' => $validated['dry_run'] ?? false,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return redirect()->route('futures-bots.show', $futures_bot)->with('success', __('futures.bot_updated'));
    }

    public function destroy(FuturesBot $futures_bot)
    {
        if ($futures_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $futures_bot->delete();
        return redirect()->route('futures-bots.index')->with('success', __('futures.bot_deleted'));
    }

    public function toggleActive(FuturesBot $futures_bot)
    {
        if ($futures_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $futures_bot->update(['is_active' => ! $futures_bot->is_active]);
        return redirect()->back()->with('success', $futures_bot->is_active ? __('futures.bot_activated') : __('futures.bot_deactivated'));
    }
}
