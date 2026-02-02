<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('bots.bot_number', ['id' => $bot->id]) }} - {{ $bot->symbol }}
            </h2>
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <a href="{{ route('bots.edit', $bot) }}" class="shrink-0 bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm whitespace-nowrap">
                    {{ __('bots.edit') }}
                </a>
                <form action="{{ route('bots.reset-risk-baseline', $bot) }}" method="POST" class="shrink-0 inline" onsubmit="return confirm('{{ __('bots.reset_risk_baseline_confirm') }}');">
                    @csrf
                    <button type="submit" class="bg-amber-100 hover:bg-amber-200 text-black font-bold py-2 px-4 rounded text-sm whitespace-nowrap border-2 border-amber-600">
                        {{ __('bots.reset_risk_baseline_short') }}
                    </button>
                </form>
                <a href="{{ route('bots.index') }}" class="shrink-0 bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm whitespace-nowrap">
                    {{ __('bots.back_to_list') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            <!-- Статистика -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('bots.total_trades') }}</div>
                        <div class="text-3xl font-bold">{{ $stats['total_trades'] }}</div>
                        <div class="text-xs text-gray-400 mt-2">{{ __('bots.filled_trades') }}: {{ $stats['filled_trades'] }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('bots.total_pnl') }}</div>
                        <div class="text-3xl font-bold {{ $stats['total_pnl'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($stats['total_pnl'], 8) }} USDT
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('bots.win_rate') }}</div>
                        <div class="text-3xl font-bold">{{ $stats['win_rate'] }}%</div>
                        <div class="text-xs text-gray-400 mt-2">
                            {{ $stats['winning_trades'] }} {{ __('bots.winning_trades') }} / {{ $stats['losing_trades'] }} {{ __('bots.losing_trades') }}
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('bots.status') }}</div>
                        <div class="text-3xl font-bold">
                            @if($bot->is_active)
                                <span class="text-green-600">{{ __('bots.active') }}</span>
                            @else
                                <span class="text-gray-600">{{ __('bots.inactive') }}</span>
                            @endif
                        </div>
                        @if($bot->dry_run)
                            <div class="text-xs text-blue-600 mt-2">{{ __('bots.dry_run') }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Расширенная статистика по боту -->
            @if($stats['closed_positions'] > 0)
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4">
                        <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.average_pnl') }}</div>
                        <div class="text-xl font-bold {{ $stats['avg_pnl'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($stats['avg_pnl'], 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4">
                        <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.profit_factor') }}</div>
                        <div class="text-xl font-bold {{ $stats['profit_factor'] >= 1.5 ? 'text-green-600' : ($stats['profit_factor'] >= 1 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ number_format($stats['profit_factor'], 2) }}
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4">
                        <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.max_drawdown') }}</div>
                        <div class="text-xl font-bold text-red-600">{{ number_format($stats['max_drawdown'], 4) }} USDT</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4">
                        <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.best_trade') }}</div>
                        <div class="text-xl font-bold text-green-600">+{{ number_format($stats['best_trade'], 4) }} USDT</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4">
                        <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.worst_trade') }}</div>
                        <div class="text-xl font-bold text-red-600">{{ number_format($stats['worst_trade'], 4) }} USDT</div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Информация о боте -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">{{ __('bots.bot_settings') }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.symbol') }}</div>
                            <div class="font-medium">{{ $bot->symbol }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.timeframe') }}</div>
                            <div class="font-medium">{{ $bot->timeframe }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.strategy') }}</div>
                            <div class="font-medium">{{ $bot->strategy }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.position_size') }}</div>
                            <div class="font-medium">{{ number_format($bot->position_size, 8) }} USDT</div>
                        </div>
                        @if($bot->rsi_period)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.rsi_period') }}</div>
                                <div class="font-medium">{{ $bot->rsi_period }}</div>
                            </div>
                        @endif
                        @if($bot->ema_period)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.ema_period') }}</div>
                                <div class="font-medium">{{ $bot->ema_period }}</div>
                            </div>
                        @endif
                        @if($bot->rsi_buy_threshold !== null || $bot->rsi_sell_threshold !== null)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.rsi_buy_threshold') }} / {{ __('bots.rsi_sell_threshold') }}</div>
                                <div class="font-medium">{{ $bot->rsi_buy_threshold ?? '—' }} / {{ $bot->rsi_sell_threshold ?? '—' }}</div>
                            </div>
                        @endif
                        @if($bot->stop_loss_percent)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.stop_loss') }}</div>
                                <div class="font-medium text-red-600">-{{ number_format($bot->stop_loss_percent, 2) }}%</div>
                            </div>
                        @endif
                        @if($bot->take_profit_percent)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.take_profit') }}</div>
                                <div class="font-medium text-green-600">+{{ number_format($bot->take_profit_percent, 2) }}%</div>
                            </div>
                        @endif
                        @if($bot->max_daily_loss_usdt !== null)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.max_daily_loss_usdt') }}</div>
                                <div class="font-medium text-orange-600">{{ number_format($bot->max_daily_loss_usdt, 2) }} USDT</div>
                            </div>
                        @endif
                        @if($bot->max_drawdown_percent !== null)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.max_drawdown_percent') }}</div>
                                <div class="font-medium text-orange-600">{{ number_format($bot->max_drawdown_percent, 2) }}%</div>
                            </div>
                        @endif
                        @if($bot->max_losing_streak !== null)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.max_losing_streak') }}</div>
                                <div class="font-medium text-orange-600">{{ $bot->max_losing_streak }}</div>
                            </div>
                        @endif
                        @if($bot->risk_drawdown_reset_at)
                            <div>
                                <div class="text-sm text-gray-500">{{ __('bots.risk_drawdown_reset_at') }}</div>
                                <div class="font-medium text-gray-600">{{ $bot->risk_drawdown_reset_at->format('Y-m-d H:i') }}</div>
                            </div>
                        @endif
                        <div class="col-span-full pt-2 border-t border-gray-200">
                            <form action="{{ route('bots.reset-risk-baseline', $bot) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('bots.reset_risk_baseline_confirm') }}');">
                                @csrf
                                <button type="submit" class="bg-amber-100 hover:bg-amber-200 text-black font-bold py-2 px-4 rounded text-sm border-2 border-amber-600">
                                    {{ __('bots.reset_risk_baseline') }}
                                </button>
                            </form>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.use_macd_filter') }}</div>
                            <div class="font-medium">{{ $bot->use_macd_filter ? __('common.yes') : __('common.no') }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ __('bots.exchange') }}</div>
                            <div class="font-medium">{{ strtoupper($bot->exchangeAccount->exchange ?? 'N/A') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- График PnL по дням -->
            @if($dailyPnL->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">{{ __('bots.daily_pnl') }}</h3>
                    <div class="relative h-56">
                        <canvas id="botPnlChart" height="200"></canvas>
                    </div>
                    <div class="mt-4 space-y-2 max-h-48 overflow-y-auto">
                        @foreach($dailyPnL->take(14) as $day)
                            <div class="flex justify-between items-center p-2 {{ $day['pnl'] >= 0 ? 'bg-green-50' : 'bg-red-50' }}">
                                <span class="text-sm font-medium">{{ $day['date'] }}</span>
                                <span class="text-sm font-bold {{ $day['pnl'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ number_format($day['pnl'], 4) }} USDT
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
                (function() {
                    var labels = @json($dailyPnL->pluck('date')->values());
                    var data = @json($dailyPnL->pluck('pnl')->values());
                    if (labels.length && data.length) {
                        new Chart(document.getElementById('botPnlChart'), {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'PnL USDT',
                                    data: data,
                                    backgroundColor: data.map(function(v) { return v >= 0 ? 'rgba(34, 197, 94, 0.7)' : 'rgba(239, 68, 68, 0.7)'; })
                                }]
                            },
                            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                        });
                    }
                })();
            </script>
            @endif

            <!-- Последние сделки -->
            @if($bot->trades->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">{{ __('bots.recent_trades') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.date') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.side') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.quantity') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.price') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.status') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('bots.pnl') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($bot->trades as $trade)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $trade->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $trade->side === 'BUY' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $trade->side }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ number_format($trade->quantity, 8) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ${{ number_format($trade->price, 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            {{ $trade->status === 'FILLED' ? 'bg-green-100 text-green-800' : 
                                               ($trade->status === 'FAILED' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                            {{ $trade->status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $trade->realized_pnl > 0 ? 'text-green-600' : ($trade->realized_pnl < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                        @if($trade->realized_pnl)
                                            {{ number_format($trade->realized_pnl, 8) }} USDT
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
