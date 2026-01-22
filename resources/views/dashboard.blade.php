<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Статистика по ботам -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Всего ботов</div>
                        <div class="text-3xl font-bold">{{ $totalBots }}</div>
                        <div class="text-xs text-gray-400 mt-2">
                            Активных: <span class="font-semibold text-green-600">{{ $activeBots }}</span>
                            @if($dryRunBots > 0)
                                | DRY RUN: <span class="font-semibold text-blue-600">{{ $dryRunBots }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Всего сделок</div>
                        <div class="text-3xl font-bold">{{ $totalTrades }}</div>
                        <div class="text-xs text-gray-400 mt-2">
                            Выполнено: <span class="font-semibold">{{ $filledTrades }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Общий PnL</div>
                        <div class="text-3xl font-bold {{ $totalPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($totalPnL, 8) }} USDT
                        </div>
                        @if($closedPositionsCount > 0)
                            <div class="text-xs text-gray-400 mt-2">
                                Win Rate: <span class="font-semibold">{{ $winRate }}%</span>
                                ({{ $winningTrades }}/{{ $closedPositionsCount }})
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Статистика по сделкам -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Прибыльных сделок</div>
                        <div class="text-3xl font-bold text-green-600">{{ $winningTrades }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Убыточных сделок</div>
                        <div class="text-3xl font-bold text-red-600">{{ $losingTrades }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Открытых позиций</div>
                        <div class="text-3xl font-bold text-blue-600">{{ $openPositions->count() }}</div>
                    </div>
                </div>
            </div>

            <!-- Сохраненная статистика (из cron) -->
            @if($savedStats)
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-blue-900">
                        📊 Статистика {{ $savedStats->days_period == 0 ? 'за все время' : 'за ' . $savedStats->days_period . ' дней' }} (обновлено: {{ $savedStats->updated_at->format('Y-m-d H:i') }})
                    </h3>
                    <span class="text-xs text-blue-600">Автоматически обновляется каждый день в 00:00</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-blue-700 mb-1">Win Rate</div>
                        <div class="text-2xl font-bold text-blue-900">{{ number_format($savedStats->win_rate, 2) }}%</div>
                    </div>
                    <div>
                        <div class="text-sm text-blue-700 mb-1">Profit Factor</div>
                        <div class="text-2xl font-bold {{ $savedStats->profit_factor >= 1.5 ? 'text-green-600' : ($savedStats->profit_factor >= 1 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ number_format($savedStats->profit_factor, 2) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-blue-700 mb-1">Сделок</div>
                        <div class="text-2xl font-bold text-blue-900">{{ $savedStats->total_trades }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-blue-700 mb-1">Средний PnL</div>
                        <div class="text-2xl font-bold {{ $savedStats->avg_pnl >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($savedStats->avg_pnl, 4) }} USDT
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Расширенные метрики -->
            @if($closedPositionsCount > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Средний PnL</div>
                        <div class="text-2xl font-bold {{ $avgPnL >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($avgPnL, 4) }} USDT
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Profit Factor</div>
                        <div class="text-2xl font-bold {{ $profitFactor >= 1.5 ? 'text-green-600' : ($profitFactor >= 1 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ number_format($profitFactor, 2) }}
                        </div>
                        <div class="text-xs text-gray-400 mt-1">
                            @if($profitFactor >= 1.5)
                                Отлично
                            @elseif($profitFactor >= 1)
                                Хорошо
                            @else
                                Требует внимания
                            @endif
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Макс. просадка</div>
                        <div class="text-2xl font-bold text-red-600">
                            {{ number_format($maxDrawdown, 4) }} USDT
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="text-sm text-gray-500 mb-1">Лучшая / Худшая</div>
                        <div class="text-lg font-bold text-green-600">
                            +{{ number_format($bestTrade, 4) }}
                        </div>
                        <div class="text-lg font-bold text-red-600">
                            {{ number_format($worstTrade, 4) }}
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Открытые позиции -->
            @if($openPositions->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Открытые позиции</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Символ</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Количество</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Цена входа</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата покупки</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Бот</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($openPositions as $position)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $position->symbol }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ number_format($position->quantity, 8) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${{ number_format($position->price, 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $position->filled_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">#{{ $position->bot->id }} ({{ $position->bot->symbol }})</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Последние сделки -->
            @if($recentTrades->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Последние сделки</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Сторона</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Символ</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Количество</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Цена</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PnL</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($recentTrades as $trade)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $trade->created_at->format('Y-m-d H:i') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $trade->side === 'BUY' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $trade->side }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $trade->symbol }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ number_format($trade->quantity, 8) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${{ number_format($trade->price, 2) }}</td>
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

            <!-- Список ботов -->
            @if($bots->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Торговые боты</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($bots as $bot)
                        <div class="border rounded-lg p-4 {{ $bot->is_active ? 'border-green-300 bg-green-50' : 'border-gray-300' }}">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h4 class="font-semibold text-lg">{{ $bot->symbol }}</h4>
                                    <p class="text-sm text-gray-500">Бот #{{ $bot->id }}</p>
                                </div>
                                <div class="flex flex-col items-end">
                                    @if($bot->is_active)
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 mb-1">Активен</span>
                                    @else
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 mb-1">Неактивен</span>
                                    @endif
                                    @if($bot->dry_run)
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">DRY RUN</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-sm space-y-1">
                                <div><span class="text-gray-500">Стратегия:</span> <span class="font-medium">{{ $bot->strategy }}</span></div>
                                <div><span class="text-gray-500">Таймфрейм:</span> <span class="font-medium">{{ $bot->timeframe }}</span></div>
                                <div><span class="text-gray-500">Размер позиции:</span> <span class="font-medium">{{ $bot->position_size }} USDT</span></div>
                                @if($bot->stop_loss_percent || $bot->take_profit_percent)
                                    <div class="pt-1 border-t border-gray-200 mt-1">
                                        @if($bot->stop_loss_percent)
                                            <div><span class="text-gray-500">Stop-Loss:</span> <span class="font-medium text-red-600">-{{ number_format($bot->stop_loss_percent, 2) }}%</span></div>
                                        @endif
                                        @if($bot->take_profit_percent)
                                            <div><span class="text-gray-500">Take-Profit:</span> <span class="font-medium text-green-600">+{{ number_format($bot->take_profit_percent, 2) }}%</span></div>
                                        @endif
                                    </div>
                                @endif
                                <div><span class="text-gray-500">Биржа:</span> <span class="font-medium">{{ strtoupper($bot->exchangeAccount->exchange ?? 'N/A') }}</span></div>
                                @if($bot->last_trade_at)
                                    <div><span class="text-gray-500">Последняя сделка:</span> <span class="font-medium">{{ $bot->last_trade_at->format('Y-m-d H:i') }}</span></div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @else
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 text-center">
                    <p class="text-gray-500">У вас пока нет торговых ботов.</p>
                    <p class="text-sm text-gray-400 mt-2">Создайте бота через консольную команду или веб-интерфейс.</p>
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
