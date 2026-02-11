<?php

namespace App\Http\Controllers;

use App\Models\BtcQuoteBot;
use App\Models\ExchangeAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BtcQuoteBotController extends Controller
{
    public function index()
    {
        $bots = BtcQuoteBot::where('user_id', Auth::id())
            ->with(['exchangeAccount', 'btcQuoteTrades' => fn ($q) => $q->latest()->limit(5)])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('btc-quote-bots.index', compact('bots'));
    }

    public function create()
    {
        $accounts = ExchangeAccount::where('user_id', Auth::id())->where('exchange', 'okx')->get();
        return view('btc-quote-bots.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}BTC$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size_btc' => ['required', 'numeric', 'min:0.00001'],
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
            return back()->withErrors(['rsi_sell_threshold' => __('btc_quote.rsi_sell_must_exceed_buy')])->withInput();
        }

        ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->where('exchange', 'okx')
            ->firstOrFail();

        BtcQuoteBot::create([
            'user_id' => Auth::id(),
            'exchange_account_id' => $validated['exchange_account_id'],
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'position_size_btc' => $validated['position_size_btc'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'max_daily_loss_btc' => isset($validated['max_daily_loss_btc']) && $validated['max_daily_loss_btc'] !== '' ? (float) $validated['max_daily_loss_btc'] : null,
            'max_losing_streak' => isset($validated['max_losing_streak']) && $validated['max_losing_streak'] !== '' ? (int) $validated['max_losing_streak'] : null,
            'rsi_period' => $validated['rsi_period'] ?? 17,
            'ema_period' => $validated['ema_period'] ?? 10,
            'rsi_buy_threshold' => $buy,
            'rsi_sell_threshold' => $sell,
            'is_active' => $validated['is_active'] ?? false,
            'dry_run' => $validated['dry_run'] ?? true,
        ]);

        return redirect()->route('btc-quote-bots.index')->with('success', __('btc_quote.bot_created'));
    }

    public function show(BtcQuoteBot $btc_quote_bot)
    {
        if ($btc_quote_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $btc_quote_bot->load(['exchangeAccount', 'btcQuoteTrades' => fn ($q) => $q->latest()->limit(50)]);
        $trades = $btc_quote_bot->btcQuoteTrades;
        $closedWithPnl = $trades->filter(fn ($t) => $t->realized_pnl_btc !== null);
        $stats = [
            'total_trades' => $btc_quote_bot->btcQuoteTrades()->count(),
            'total_pnl_btc' => (float) $btc_quote_bot->btcQuoteTrades()->whereNotNull('realized_pnl_btc')->sum('realized_pnl_btc'),
            'winning_trades' => $closedWithPnl->where('realized_pnl_btc', '>', 0)->count(),
            'losing_trades' => $closedWithPnl->where('realized_pnl_btc', '<', 0)->count(),
            'win_rate' => $closedWithPnl->count() > 0
                ? round(($closedWithPnl->where('realized_pnl_btc', '>', 0)->count() / $closedWithPnl->count()) * 100, 2)
                : 0,
        ];
        return view('btc-quote-bots.show', ['bot' => $btc_quote_bot, 'trades' => $trades, 'stats' => $stats]);
    }

    public function edit(BtcQuoteBot $btc_quote_bot)
    {
        if ($btc_quote_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $accounts = ExchangeAccount::where('user_id', Auth::id())->where('exchange', 'okx')->get();
        return view('btc-quote-bots.edit', ['bot' => $btc_quote_bot, 'accounts' => $accounts]);
    }

    public function update(Request $request, BtcQuoteBot $btc_quote_bot)
    {
        if ($btc_quote_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}BTC$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size_btc' => ['required', 'numeric', 'min:0.00001'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_daily_loss_btc' => ['nullable', 'numeric', 'min:0'],
            'max_losing_streak' => ['nullable', 'integer', 'min:1', 'max:20'],
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
            return back()->withErrors(['rsi_sell_threshold' => __('btc_quote.rsi_sell_must_exceed_buy')])->withInput();
        }

        ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->where('exchange', 'okx')
            ->firstOrFail();

        $btc_quote_bot->update([
            'exchange_account_id' => $validated['exchange_account_id'],
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'position_size_btc' => $validated['position_size_btc'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'max_daily_loss_btc' => isset($validated['max_daily_loss_btc']) && $validated['max_daily_loss_btc'] !== '' ? (float) $validated['max_daily_loss_btc'] : null,
            'max_losing_streak' => isset($validated['max_losing_streak']) && $validated['max_losing_streak'] !== '' ? (int) $validated['max_losing_streak'] : null,
            'rsi_period' => $validated['rsi_period'] ?? 17,
            'ema_period' => $validated['ema_period'] ?? 10,
            'rsi_buy_threshold' => $buy,
            'rsi_sell_threshold' => $sell,
            'dry_run' => $validated['dry_run'] ?? false,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return redirect()->route('btc-quote-bots.show', $btc_quote_bot)->with('success', __('btc_quote.bot_updated'));
    }

    public function destroy(BtcQuoteBot $btc_quote_bot)
    {
        if ($btc_quote_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $btc_quote_bot->delete();
        return redirect()->route('btc-quote-bots.index')->with('success', __('btc_quote.bot_deleted'));
    }

    public function toggleActive(BtcQuoteBot $btc_quote_bot)
    {
        if ($btc_quote_bot->user_id !== Auth::id()) {
            abort(403);
        }
        $btc_quote_bot->update(['is_active' => ! $btc_quote_bot->is_active]);
        return redirect()->back()->with('success', $btc_quote_bot->is_active ? __('btc_quote.bot_activated') : __('btc_quote.bot_deactivated'));
    }
}
