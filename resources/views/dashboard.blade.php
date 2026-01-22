<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Балансы аккаунтов -->
            @if(!empty($accountBalances))
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-semibold text-gray-900">💰 Балансы аккаунтов</h3>
                        @if($totalBalanceUsdt > 0)
                            <span class="text-lg font-bold text-indigo-600">{{ number_format($totalBalanceUsdt, 2) }} USDT</span>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($accountBalances as $account)
                        <div class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-gray-700">{{ $account['exchange'] }}</span>
                                <span class="text-base font-bold {{ $account['total_usdt'] > 0 ? 'text-green-600' : 'text-gray-500' }}">
                                    {{ number_format($account['total_usdt'], 2) }} USDT
                                </span>
                            </div>
                            <div class="space-y-1 mt-2">
                                @foreach($account['balances'] as $coin => $amount)
                                    @if($amount > 0.00000001)
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-gray-600">{{ $coin }}:</span>
                                            <span class="font-medium text-gray-900">{{ number_format($amount, 8) }}</span>
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

            <!-- Ключевые метрики (компактно) -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Ботов</div>
                        <div class="text-xl font-bold text-gray-900">{{ $totalBots }}</div>
                        <div class="text-xs text-gray-500 mt-1">Активных: <span class="font-semibold text-green-600">{{ $activeBots }}</span></div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Сделок</div>
                        <div class="text-xl font-bold text-gray-900">{{ $totalTrades }}</div>
                        <div class="text-xs text-gray-500 mt-1">Выполнено: <span class="font-semibold">{{ $filledTrades }}</span></div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Общий PnL</div>
                        <div class="text-xl font-bold {{ $totalPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($totalPnL, 4) }} USDT
                        </div>
                        @if($closedPositionsCount > 0)
                            <div class="text-xs text-gray-500 mt-1">Win Rate: <span class="font-semibold">{{ $winRate }}%</span></div>
                        @endif
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Прибыльных</div>
                        <div class="text-xl font-bold text-green-600">{{ $winningTrades }}</div>
                        <div class="text-xs text-gray-500 mt-1">Убыточных: <span class="font-semibold text-red-600">{{ $losingTrades }}</span></div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Открытых</div>
                        <div class="text-xl font-bold text-blue-600">{{ $openPositions->count() }}</div>
                        <div class="text-xs text-gray-500 mt-1">позиций</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Profit Factor</div>
                        <div class="text-xl font-bold {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ number_format($profitFactor, 2) }}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            @if($profitFactor >= 1.5) <span class="font-semibold text-green-600">Отлично</span>
                            @elseif($profitFactor >= 1) <span class="font-semibold text-yellow-600">Хорошо</span>
                            @else <span class="font-semibold text-red-600">Требует внимания</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Сохраненная статистика (компактно) -->
            @if($savedStats)
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-blue-900">
                        📊 Статистика {{ $savedStats->days_period == 0 ? 'за все время' : 'за ' . $savedStats->days_period . ' дней' }}
                    </h3>
                    <span class="text-xs text-blue-600">{{ $savedStats->updated_at->format('Y-m-d H:i') }}</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <div class="text-xs text-blue-700 mb-1">Win Rate</div>
                        <div class="text-lg font-bold text-blue-900">{{ number_format($savedStats->win_rate, 2) }}%</div>
                    </div>
                    <div>
                        <div class="text-xs text-blue-700 mb-1">Profit Factor</div>
                        <div class="text-lg font-bold {{ $savedStats->profit_factor >= 1.5 ? 'text-green-600' : ($savedStats->profit_factor >= 1 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ number_format($savedStats->profit_factor, 2) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-blue-700 mb-1">Сделок</div>
                        <div class="text-lg font-bold text-blue-900">{{ $savedStats->total_trades }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-blue-700 mb-1">Средний PnL</div>
                        <div class="text-lg font-bold {{ $savedStats->avg_pnl >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($savedStats->avg_pnl, 4) }} USDT
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Расширенные метрики (компактно) -->
            @if($closedPositionsCount > 0)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Средний PnL</div>
                        <div class="text-lg font-bold {{ $avgPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($avgPnL, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Макс. просадка</div>
                        <div class="text-lg font-bold text-red-600">
                            {{ number_format($maxDrawdown, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Лучшая сделка</div>
                        <div class="text-lg font-bold text-green-600">
                            +{{ number_format($bestTrade, 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-3">
                        <div class="text-xs text-gray-500 mb-1">Худшая сделка</div>
                        <div class="text-lg font-bold text-red-600">
                            {{ number_format($worstTrade, 4) }} USDT
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Открытые позиции (компактно) -->
            @if($openPositions->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold">Открытые позиции ({{ $openPositions->count() }})</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Символ</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Количество</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Цена входа</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Бот</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($openPositions as $position)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">{{ $position->symbol }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">{{ number_format($position->quantity, 8) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">${{ number_format($position->price, 2) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">#{{ $position->bot->id }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Быстрые ссылки -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <a href="{{ route('trades.index') }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">📊 Все сделки</h3>
                            <p class="text-xs text-gray-500 mt-1">Просмотр всех сделок с фильтрами</p>
                        </div>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </a>
                <a href="{{ route('bots.index') }}" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">🤖 Торговые боты</h3>
                            <p class="text-xs text-gray-500 mt-1">Управление торговыми ботами</p>
                        </div>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </a>
            </div>

        </div>
    </div>
</x-app-layout>
