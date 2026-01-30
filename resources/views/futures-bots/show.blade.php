<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('futures.bot_number', ['id' => $bot->id]) }} — {{ $bot->symbol }}
            </h2>
            <div class="flex gap-2">
                <a href="{{ route('futures-bots.edit', $bot) }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                    {{ __('futures.edit') }}
                </a>
                <a href="{{ route('futures-bots.index') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                    {{ __('futures.back_to_list') }}
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
                        <div class="text-sm text-gray-500 mb-1">{{ __('futures.stats_total_trades') }}</div>
                        <div class="text-3xl font-bold">{{ $stats['total_trades'] }}</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('futures.stats_total_pnl') }}</div>
                        <div class="text-3xl font-bold {{ $stats['total_pnl'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($stats['total_pnl'], 4) }} USDT
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('futures.stats_win_rate') }}</div>
                        <div class="text-3xl font-bold">{{ $stats['win_rate'] }}%</div>
                        <div class="text-xs text-gray-400 mt-2">
                            {{ $stats['winning_trades'] }} {{ __('futures.winning_trades') }} / {{ $stats['losing_trades'] }} {{ __('futures.losing_trades') }}
                        </div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm text-gray-500 mb-1">{{ __('futures.status') }}</div>
                        <div class="text-xl font-bold">
                            @if($bot->is_active)
                                <span class="text-green-600">{{ __('futures.active') }}</span>
                            @else
                                <span class="text-gray-600">{{ __('futures.inactive') }}</span>
                            @endif
                        </div>
                        @if($bot->dry_run)
                            <div class="text-xs text-blue-600 mt-2">{{ __('futures.dry_run') }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Параметры бота -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('futures.bot_params') }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div><span class="text-gray-500">{{ __('futures.strategy') }}:</span> {{ $bot->strategy }}</div>
                        <div><span class="text-gray-500">{{ __('futures.timeframe') }}:</span> {{ $bot->timeframe }}</div>
                        <div><span class="text-gray-500">{{ __('futures.position_size_usdt') }}:</span> {{ number_format($bot->position_size_usdt, 2) }} USDT</div>
                        <div><span class="text-gray-500">{{ __('futures.leverage') }}:</span> {{ $bot->leverage }}x</div>
                        <div><span class="text-gray-500">{{ __('futures.rsi_period') }}:</span> {{ $bot->rsi_period ?? '—' }}</div>
                        <div><span class="text-gray-500">{{ __('futures.ema_period') }}:</span> {{ $bot->ema_period ?? '—' }}</div>
                        <div><span class="text-gray-500">{{ __('futures.rsi_buy_threshold') }}:</span> {{ $bot->rsi_buy_threshold ?? '—' }}</div>
                        <div><span class="text-gray-500">{{ __('futures.rsi_sell_threshold') }}:</span> {{ $bot->rsi_sell_threshold ?? '—' }}</div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <form action="{{ route('futures-bots.toggle-active', $bot) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="{{ $bot->is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' }} text-white font-medium py-1.5 px-3 rounded-md text-sm">
                                {{ $bot->is_active ? __('futures.deactivate') : __('futures.activate') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Список сделок -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('futures.recent_trades') }}</h3>
                    @if($trades->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('futures.date') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('futures.side') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('futures.quantity') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('futures.price') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('futures.status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PnL</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($trades as $trade)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $trade->created_at->format('Y-m-d H:i') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $trade->side === 'BUY' ? 'text-green-600' : 'text-red-600' }}">{{ $trade->side }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $trade->quantity }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format((float) $trade->price, 2) }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $trade->status ?? '—' }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $trade->realized_pnl > 0 ? 'text-green-600' : ($trade->realized_pnl < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                                @if($trade->realized_pnl !== null)
                                                    {{ number_format((float) $trade->realized_pnl, 4) }} USDT
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-gray-500">{{ __('futures.no_trades') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
