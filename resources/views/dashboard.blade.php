<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Балансы аккаунтов -->
            @if(!empty($accountBalances))
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl shadow-lg border border-blue-100 overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-2xl">💰</span>
                            <h3 class="text-xl font-bold text-gray-900">Балансы аккаунтов</h3>
                        </div>
                        @if($totalBalanceUsdt > 0)
                            <div class="text-right">
                                <div class="text-sm text-gray-600 mb-1">Общий баланс</div>
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

            <!-- Основные метрики -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Ботов</div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">{{ $totalBots }}</div>
                        <div class="text-xs text-gray-500">
                            Активных: <span class="font-semibold text-green-600">{{ $activeBots }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Сделок</div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">{{ $totalTrades }}</div>
                        <div class="text-xs text-gray-500">
                            Выполнено: <span class="font-semibold">{{ $filledTrades }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Общий PnL</div>
                        <div class="text-2xl font-bold {{ $totalPnL >= 0 ? 'text-green-600' : 'text-red-600' }} mb-1">
                            {{ number_format($totalPnL, 4) }} USDT
                        </div>
                        @if($closedPositionsCount > 0)
                            <div class="text-xs text-gray-500">
                                Win Rate: <span class="font-semibold">{{ $winRate }}%</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Прибыльных</div>
                        <div class="text-2xl font-bold text-green-600 mb-1">{{ $winningTrades }}</div>
                        <div class="text-xs text-gray-500">
                            Убыточных: <span class="font-semibold text-red-600">{{ $losingTrades }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Открытых</div>
                        <div class="text-2xl font-bold text-blue-600 mb-1">{{ $openPositions->count() }}</div>
                        <div class="text-xs text-gray-500">позиций</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Profit Factor</div>
                        <div class="text-2xl font-bold {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }} mb-1">
                            {{ number_format($profitFactor, 2) }}
                        </div>
                        <div class="text-xs {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }} font-medium">
                            @if($profitFactor >= 1.5) Отлично
                            @elseif($profitFactor >= 1) Хорошо
                            @else Требует внимания
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Сохраненная статистика -->
            @if($savedStats)
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl shadow-lg text-white overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-2xl">📊</span>
                            <h3 class="text-lg font-bold">Статистика {{ $savedStats->days_period == 0 ? 'за все время' : 'за ' . $savedStats->days_period . ' дней' }}</h3>
                        </div>
                        <span class="text-xs text-blue-100">{{ $savedStats->updated_at->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-3">
                            <div class="text-xs text-blue-100 mb-1">Win Rate</div>
                            <div class="text-2xl font-bold">{{ number_format($savedStats->win_rate, 2) }}%</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-3">
                            <div class="text-xs text-blue-100 mb-1">Profit Factor</div>
                            <div class="text-2xl font-bold">{{ number_format($savedStats->profit_factor, 2) }}</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-3">
                            <div class="text-xs text-blue-100 mb-1">Сделок</div>
                            <div class="text-2xl font-bold">{{ $savedStats->total_trades }}</div>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-3">
                            <div class="text-xs text-blue-100 mb-1">Средний PnL</div>
                            <div class="text-2xl font-bold">{{ number_format($savedStats->avg_pnl, 4) }} USDT</div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Расширенные метрики -->
            @if($closedPositionsCount > 0)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Средний PnL</div>
                        <div class="text-xl font-bold {{ $avgPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($avgPnL, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Макс. просадка</div>
                        <div class="text-xl font-bold text-red-600">
                            {{ number_format($maxDrawdown, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Лучшая сделка</div>
                        <div class="text-xl font-bold text-green-600">
                            +{{ number_format($bestTrade, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Худшая сделка</div>
                        <div class="text-xl font-bold text-red-600">
                            {{ number_format($worstTrade, 4) }} USDT
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Открытые позиции -->
            @if($openPositions->count() > 0)
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Открытые позиции</h3>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">{{ $openPositions->count() }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Символ</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Количество</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Цена входа</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Бот</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($openPositions as $position)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $position->symbol }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{{ number_format($position->quantity, 8) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">${{ number_format($position->price, 2) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">#{{ $position->bot->id }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Быстрые ссылки -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="{{ route('trades.index') }}" class="group bg-white rounded-xl shadow-md border border-gray-200 p-6 hover:shadow-xl hover:border-indigo-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 transition-colors">
                                <span class="text-2xl">📊</span>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900 group-hover:text-indigo-600 transition-colors">Все сделки</h3>
                                <p class="text-sm text-gray-500 mt-1">Просмотр всех сделок с фильтрами</p>
                            </div>
                        </div>
                        <svg class="w-6 h-6 text-gray-400 group-hover:text-indigo-600 group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </a>
                <a href="{{ route('bots.index') }}" class="group bg-white rounded-xl shadow-md border border-gray-200 p-6 hover:shadow-xl hover:border-indigo-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                                <span class="text-2xl">🤖</span>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900 group-hover:text-indigo-600 transition-colors">Торговые боты</h3>
                                <p class="text-sm text-gray-500 mt-1">Управление торговыми ботами</p>
                            </div>
                        </div>
                        <svg class="w-6 h-6 text-gray-400 group-hover:text-indigo-600 group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </a>
            </div>

        </div>
    </div>
</x-app-layout>
