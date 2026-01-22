<?php

namespace App\Http\Controllers;

use App\Models\ExchangeAccount;
use App\Models\TradingBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BotController extends Controller
{
    /**
     * Display a listing of the bots.
     */
    public function index()
    {
        $bots = TradingBot::where('user_id', Auth::id())
            ->with(['exchangeAccount', 'trades' => function ($query) {
                $query->latest()->limit(5);
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('bots.index', compact('bots'));
    }

    /**
     * Show the form for creating a new bot.
     */
    public function create()
    {
        $accounts = ExchangeAccount::where('user_id', Auth::id())->get();
        
        return view('bots.create', compact('accounts'));
    }

    /**
     * Store a newly created bot.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}USDT$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size' => ['required', 'numeric', 'min:1'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rsi_period' => ['nullable', 'integer', 'min:2', 'max:100'],
            'ema_period' => ['nullable', 'integer', 'min:2', 'max:200'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        // Проверяем, что аккаунт принадлежит пользователю
        $account = ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $bot = TradingBot::create([
            'user_id' => Auth::id(),
            'exchange_account_id' => $account->id,
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $validated['timeframe'],
            'strategy' => $validated['strategy'],
            'position_size' => $validated['position_size'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'rsi_period' => $validated['rsi_period'] ?? null,
            'ema_period' => $validated['ema_period'] ?? null,
            'dry_run' => $validated['dry_run'] ?? false,
            'is_active' => false, // По умолчанию неактивен
        ]);

        return redirect()->route('bots.show', $bot)
            ->with('success', __('bots.bot_created'));
    }

    /**
     * Display the specified bot.
     */
    public function show(TradingBot $bot)
    {
        // Проверяем, что бот принадлежит пользователю
        if ($bot->user_id !== Auth::id()) {
            abort(403);
        }

        $bot->load(['exchangeAccount', 'trades' => function ($query) {
            $query->latest()->limit(50);
        }]);

        // Статистика по боту
        $trades = $bot->trades;
        $filledTrades = $trades->where('status', 'FILLED');
        $closedPositions = $trades->whereNotNull('closed_at')->whereNotNull('realized_pnl');
        
        $stats = [
            'total_trades' => $trades->count(),
            'filled_trades' => $filledTrades->count(),
            'closed_positions' => $closedPositions->count(),
            'total_pnl' => $closedPositions->sum('realized_pnl'),
            'winning_trades' => $closedPositions->where('realized_pnl', '>', 0)->count(),
            'losing_trades' => $closedPositions->where('realized_pnl', '<', 0)->count(),
            'win_rate' => $closedPositions->count() > 0 
                ? round(($closedPositions->where('realized_pnl', '>', 0)->count() / $closedPositions->count()) * 100, 2)
                : 0,
        ];

        // График PnL по дням
        $dailyPnL = $closedPositions
            ->groupBy(function ($trade) {
                return $trade->closed_at->format('Y-m-d');
            })
            ->map(function ($trades, $date) {
                return [
                    'date' => $date,
                    'pnl' => (float) $trades->sum('realized_pnl'),
                ];
            })
            ->values()
            ->sortBy('date')
            ->take(30);

        return view('bots.show', compact('bot', 'stats', 'dailyPnL'));
    }

    /**
     * Show the form for editing the specified bot.
     */
    public function edit(TradingBot $bot)
    {
        // Проверяем, что бот принадлежит пользователю
        if ($bot->user_id !== Auth::id()) {
            abort(403);
        }

        $accounts = ExchangeAccount::where('user_id', Auth::id())->get();

        // Нормализуем таймфрейм для отображения в форме
        // "1h" -> "60", "5m" -> "5", "1" -> "1" и т.д.
        $normalizedTimeframe = $this->normalizeTimeframeForForm($bot->timeframe);

        return view('bots.edit', compact('bot', 'accounts', 'normalizedTimeframe'));
    }

    /**
     * Normalize timeframe value for form display.
     * Converts "1h" -> "60", "5m" -> "5", etc.
     */
    private function normalizeTimeframeForForm(string $timeframe): string
    {
        // Если заканчивается на "h", конвертируем в минуты
        if (preg_match('/^(\d+)h$/', $timeframe, $matches)) {
            return (string)($matches[1] * 60);
        }
        
        // Если заканчивается на "m", убираем "m"
        if (preg_match('/^(\d+)m$/', $timeframe, $matches)) {
            return $matches[1];
        }
        
        // Если уже число или D/W/M, возвращаем как есть
        return $timeframe;
    }

    /**
     * Normalize timeframe value for storage.
     * Converts "60" -> "1h", "5" -> "5m" (if < 60), etc.
     */
    private function normalizeTimeframeForStorage(string $timeframe): string
    {
        // Если это число
        if (is_numeric($timeframe)) {
            $minutes = (int)$timeframe;
            
            // Если >= 60, конвертируем в часы
            if ($minutes >= 60 && $minutes % 60 === 0) {
                return ($minutes / 60) . 'h';
            }
            
            // Иначе в минуты
            return $minutes . 'm';
        }
        
        // Если уже в формате "1h", "5m", "D", "W" - возвращаем как есть
        return $timeframe;
    }

    /**
     * Update the specified bot.
     */
    public function update(Request $request, TradingBot $bot)
    {
        // Проверяем, что бот принадлежит пользователю
        if ($bot->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'exchange_account_id' => ['required', 'exists:exchange_accounts,id'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z]{2,10}USDT$/'],
            'timeframe' => ['required', 'string'],
            'strategy' => ['required', 'string', Rule::in(['rsi_ema'])],
            'position_size' => ['required', 'numeric', 'min:1'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'take_profit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rsi_period' => ['nullable', 'integer', 'min:2', 'max:100'],
            'ema_period' => ['nullable', 'integer', 'min:2', 'max:200'],
            'dry_run' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Проверяем, что аккаунт принадлежит пользователю
        $account = ExchangeAccount::where('id', $validated['exchange_account_id'])
            ->where('user_id', Auth::id())
            ->firstOrFail();

        // Нормализуем таймфрейм: если число, конвертируем в формат "1h" для часов или "5m" для минут
        $timeframe = $this->normalizeTimeframeForStorage($validated['timeframe']);

        $bot->update([
            'exchange_account_id' => $account->id,
            'symbol' => strtoupper($validated['symbol']),
            'timeframe' => $timeframe,
            'strategy' => $validated['strategy'],
            'position_size' => $validated['position_size'],
            'stop_loss_percent' => $validated['stop_loss_percent'] ?? null,
            'take_profit_percent' => $validated['take_profit_percent'] ?? null,
            'rsi_period' => $validated['rsi_period'] ?? null,
            'ema_period' => $validated['ema_period'] ?? null,
            'dry_run' => $validated['dry_run'] ?? false,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return redirect()->route('bots.show', $bot)
            ->with('success', __('bots.bot_updated'));
    }

    /**
     * Remove the specified bot.
     */
    public function destroy(TradingBot $bot)
    {
        // Проверяем, что бот принадлежит пользователю
        if ($bot->user_id !== Auth::id()) {
            abort(403);
        }

        $bot->delete();

        return redirect()->route('bots.index')
            ->with('success', __('bots.bot_deleted'));
    }

    /**
     * Toggle bot active status.
     */
    public function toggleActive(TradingBot $bot)
    {
        // Проверяем, что бот принадлежит пользователю
        if ($bot->user_id !== Auth::id()) {
            abort(403);
        }

        $bot->update([
            'is_active' => !$bot->is_active,
        ]);

        return redirect()->back()
            ->with('success', $bot->is_active ? __('bots.bot_activated') : __('bots.bot_deactivated'));
    }
}
