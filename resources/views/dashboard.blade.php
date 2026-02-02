<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('dashboard.title') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Балансы аккаунтов -->
            @if(!empty($accountBalances))
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-2xl">💰</span>
                            <h3 class="text-xl font-bold text-gray-900">{{ __('dashboard.account_balances') }}</h3>
                        </div>
                        @if($totalBalanceUsdt > 0)
                            <div class="text-right">
                                <div class="text-sm text-gray-600 mb-1">{{ __('dashboard.total_balance') }}</div>
                                <div class="text-2xl font-bold text-indigo-600">{{ number_format($totalBalanceUsdt, 2) }} USDT</div>
                            </div>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($accountBalances as $account)
                        <div class="bg-white rounded-lg p-4 shadow-sm border border-gray-200">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-base font-bold text-gray-800">{{ $account['exchange'] }}</span>
                                <span class="text-lg font-bold {{ $account['total_usdt'] > 0 ? 'text-green-600' : 'text-gray-500' }}">
                                    {{ number_format($account['total_usdt'], 2) }} USDT
                                </span>
                            </div>
                            <div class="space-y-2">
                                @foreach($account['balances'] as $coin => $amount)
                                    @if($amount > 0.00000001)
                                        <div class="flex justify-between items-center py-1.5 border-b border-gray-100 last:border-0">
                                            <span class="text-sm font-medium text-gray-700">{{ $coin }}</span>
                                            <span class="text-sm font-semibold text-gray-900">{{ number_format($amount, 8) }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Фильтр периода -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-4">
                <div class="p-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-gray-700">{{ __('dashboard.period') }}:</span>
                    <a href="{{ route('dashboard', ['period' => 7]) }}" class="px-3 py-1.5 rounded-md text-sm font-medium {{ ($period ?? 30) == 7 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">7 {{ __('dashboard.days') }}</a>
                    <a href="{{ route('dashboard', ['period' => 30]) }}" class="px-3 py-1.5 rounded-md text-sm font-medium {{ ($period ?? 30) == 30 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">30 {{ __('dashboard.days') }}</a>
                    <a href="{{ route('dashboard', ['period' => 90]) }}" class="px-3 py-1.5 rounded-md text-sm font-medium {{ ($period ?? 30) == 90 ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">90 {{ __('dashboard.days') }}</a>
                </div>
            </div>

            <!-- Основные метрики -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex items-center space-x-2 mb-4">
                        <span class="text-2xl">📊</span>
                        <h3 class="text-xl font-bold text-gray-900">{{ __('dashboard.main_metrics') }}</h3>
                        <span class="text-sm text-gray-500">({{ __('dashboard.all_bots') }}, {{ $period ?? 30 }} {{ __('dashboard.days') }})</span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.bots') }}</div>
                                <div class="text-2xl font-bold text-gray-900 mb-1">{{ $totalBots }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ __('dashboard.active') }}: <span class="font-semibold text-green-600">{{ $activeBots }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.trades') }}</div>
                                <div class="text-2xl font-bold text-gray-900 mb-1">{{ $totalTrades }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ __('dashboard.filled') }}: <span class="font-semibold">{{ $filledTrades }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.total_pnl') }}</div>
                                <div class="text-2xl font-bold {{ $totalPnL >= 0 ? 'text-green-600' : 'text-red-600' }} mb-1">
                                    {{ number_format($totalPnL, 4) }} USDT
                                </div>
                                @if(isset($totalPnLBtcQuoteBtc) && (float)$totalPnLBtcQuoteBtc != 0)
                                    <div class="text-xs text-gray-500">
                                        {{ __('dashboard.btc_quote_pnl') }}: <span class="font-semibold">{{ number_format($totalPnLBtcQuoteBtc, 8) }} BTC</span>
                                    </div>
                                @endif
                                @if($closedPositionsCount > 0)
                                    <div class="text-xs text-gray-500">
                                        {{ __('dashboard.win_rate') }}: <span class="font-semibold">{{ $winRate }}%</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.profitable') }}</div>
                                <div class="text-2xl font-bold text-green-600 mb-1">{{ $winningTrades }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ __('dashboard.unprofitable') }}: <span class="font-semibold text-red-600">{{ $losingTrades }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.open_positions') }}</div>
                                <div class="text-2xl font-bold text-blue-600 mb-1">{{ count($openPositions) }}</div>
                                <div class="text-xs text-gray-500">{{ __('dashboard.positions') }}</div>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.profit_factor') }}</div>
                                <div class="text-2xl font-bold {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }} mb-1">
                                    {{ number_format($profitFactor, 2) }}
                                </div>
                                <div class="text-xs {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }} font-medium">
                                    @if($profitFactor >= 1.5) {{ __('dashboard.excellent') }}
                                    @elseif($profitFactor >= 1) {{ __('dashboard.good') }}
                                    @else {{ __('dashboard.needs_attention') }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Графики -->
            <div id="dashboard-charts" class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6" data-no-data-msg="{{ __('dashboard.charts_no_data') }}">
                <div class="p-6">
                    <div class="flex items-center space-x-2 mb-4">
                        <span class="text-2xl">📈</span>
                        <h3 class="text-xl font-bold text-gray-900">{{ __('dashboard.charts') }}</h3>
                        <span class="text-sm text-gray-500">({{ __('dashboard.last_days', ['days' => $period ?? 30]) }})</span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2">
                            <p class="text-sm font-medium text-gray-700 mb-2">{{ __('dashboard.chart_cumulative_pnl') }}</p>
                            <div class="relative h-64">
                                <canvas id="chartCumulativePnL" height="200"></canvas>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-2">{{ __('dashboard.chart_pnl_by_day') }}</p>
                            <div class="relative h-64">
                                <canvas id="chartPnlByDay" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6">
                        <p class="text-sm font-medium text-gray-700 mb-2">{{ __('dashboard.chart_trades_by_day') }}</p>
                        <div class="relative h-48">
                            <canvas id="chartTradesByDay" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
                (function() {
                    const defaultOptions = { responsive: true, maintainAspectRatio: false };
                    const dateLabels = @json($chartCumulativePnL['labels'] ?? []);
                    const cumulativeData = @json($chartCumulativePnL['data'] ?? []);
                    const pnlByDayData = @json($chartPnlByDay['data'] ?? []);
                    const tradesByDayData = @json($chartTradesByDay['data'] ?? []);

                    if (dateLabels.length && (cumulativeData.some(v => v !== 0) || pnlByDayData.some(v => v !== 0) || tradesByDayData.some(v => v > 0))) {
                        if (cumulativeData.length) {
                            new Chart(document.getElementById('chartCumulativePnL'), {
                                type: 'line',
                                data: {
                                    labels: dateLabels,
                                    datasets: [{
                                        label: '{{ __("dashboard.chart_cumulative_pnl") }}',
                                        data: cumulativeData,
                                        borderColor: 'rgb(79, 70, 229)',
                                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                                        fill: true,
                                        tension: 0.2
                                    }]
                                },
                                options: { ...defaultOptions, scales: { y: { beginAtZero: true } } }
                            });
                        }
                        if (pnlByDayData.length) {
                            new Chart(document.getElementById('chartPnlByDay'), {
                                type: 'bar',
                                data: {
                                    labels: dateLabels,
                                    datasets: [{
                                        label: 'PnL USDT',
                                        data: pnlByDayData,
                                        backgroundColor: pnlByDayData.map(v => v >= 0 ? 'rgba(34, 197, 94, 0.7)' : 'rgba(239, 68, 68, 0.7)')
                                    }]
                                },
                                options: { ...defaultOptions, scales: { y: { beginAtZero: true } } }
                            });
                        }
                        if (tradesByDayData.length) {
                            new Chart(document.getElementById('chartTradesByDay'), {
                                type: 'bar',
                                data: {
                                    labels: dateLabels,
                                    datasets: [{
                                        label: '{{ __("dashboard.trades") }}',
                                        data: tradesByDayData,
                                        backgroundColor: 'rgba(99, 102, 241, 0.7)'
                                    }]
                                },
                                options: { ...defaultOptions, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                            });
                        }
                    } else {
                        var el = document.getElementById('dashboard-charts');
                        if (el) el.querySelector('.p-6').innerHTML = '<div class="flex items-center space-x-2 mb-4"><span class="text-2xl">📈</span><h3 class="text-xl font-bold text-gray-900">{{ __("dashboard.charts") }}</h3></div><p class="text-gray-500 text-sm py-8 text-center">' + (el.getAttribute('data-no-data-msg') || '') + '</p>';
                    }
                })();
            </script>

            <!-- Сохраненная статистика -->
            @if($savedStats)
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-2xl">📊</span>
                            <h3 class="text-xl font-bold text-gray-900">
                                {{ $savedStats->days_period == 0 ? __('dashboard.statistics_all_time') : __('dashboard.statistics_period', ['days' => $savedStats->days_period]) }}
                            </h3>
                        </div>
                        <span class="text-xs text-gray-500">{{ $savedStats->updated_at->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.win_rate') }}</div>
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($savedStats->win_rate, 2) }}%</div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.profit_factor') }}</div>
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($savedStats->profit_factor, 2) }}</div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.trades') }}</div>
                            <div class="text-2xl font-bold text-gray-900">{{ $savedStats->total_trades }}</div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{{ __('dashboard.average_pnl') }}</div>
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($savedStats->avg_pnl, 4) }} USDT</div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Расширенные метрики -->
            @if($closedPositionsCount > 0)
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex items-center space-x-2 mb-4">
                        <span class="text-2xl">📈</span>
                        <h3 class="text-xl font-bold text-gray-900">{{ __('dashboard.statistics') }}</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.average_pnl') }}</div>
                                <div class="text-xl font-bold {{ $avgPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ number_format($avgPnL, 4) }} USDT
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.max_drawdown') }}</div>
                                <div class="text-xl font-bold text-red-600">
                                    {{ number_format($maxDrawdown, 4) }} USDT
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.best_trade') }}</div>
                                <div class="text-xl font-bold text-green-600">
                                    +{{ number_format($bestTrade, 4) }} USDT
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden">
                            <div class="p-4">
                                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('dashboard.worst_trade') }}</div>
                                <div class="text-xl font-bold text-red-600">
                                    {{ number_format($worstTrade, 4) }} USDT
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Открытые позиции -->
            @if(count($openPositions) > 0)
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-2xl">📋</span>
                            <h3 class="text-xl font-bold text-gray-900">{{ __('dashboard.open_positions_title') }}</h3>
                        </div>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">{{ count($openPositions) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ __('dashboard.position_type') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ __('dashboard.symbol') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ __('dashboard.quantity') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ __('dashboard.entry_price') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ __('dashboard.bot') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($openPositions as $position)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm">
                                        @if($position['type'] === 'spot')
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">{{ __('dashboard.type_spot') }}</span>
                                        @elseif($position['type'] === 'futures')
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">{{ __('dashboard.type_futures') }}</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">{{ __('dashboard.type_btc_quote') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $position['symbol'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{{ number_format($position['quantity'], 8) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">${{ number_format($position['price'], 2) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{{ $position['bot_label'] }}</td>
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
