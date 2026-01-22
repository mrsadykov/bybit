<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Сделки') }}
            </h2>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">
                ← {{ __('Назад к дашборду') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Фильтры -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-semibold text-gray-900">Фильтры</h3>
                        @if(request()->hasAny(['bot_id', 'symbol', 'side', 'status', 'date_from', 'date_to']))
                            <a href="{{ route('trades.index') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                ✕ Сбросить все
                            </a>
                        @endif
                    </div>
                    
                    <form method="GET" action="{{ route('trades.index') }}" id="filtersForm" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Бот</label>
                            <select name="bot_id" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Все боты</option>
                                @foreach($bots as $bot)
                                    <option value="{{ $bot->id }}" {{ request('bot_id') == $bot->id ? 'selected' : '' }}>
                                        #{{ $bot->id }} - {{ $bot->symbol }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Символ</label>
                            <select name="symbol" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Все символы</option>
                                @foreach($symbols as $symbol)
                                    <option value="{{ $symbol }}" {{ request('symbol') == $symbol ? 'selected' : '' }}>
                                        {{ $symbol }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Сторона</label>
                            <select name="side" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Все</option>
                                <option value="BUY" {{ request('side') == 'BUY' ? 'selected' : '' }}>BUY</option>
                                <option value="SELL" {{ request('side') == 'SELL' ? 'selected' : '' }}>SELL</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">Статус</label>
                            <select name="status" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Все статусы</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" {{ request('status') == $status ? 'selected' : '' }}>
                                        {{ $status }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    <!-- Активные фильтры -->
                    @if(request()->hasAny(['bot_id', 'symbol', 'side', 'status']))
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <div class="flex flex-wrap gap-2">
                                <span class="text-xs text-gray-500">Активные фильтры:</span>
                                @if(request('bot_id'))
                                    @php $selectedBot = $bots->firstWhere('id', request('bot_id')); @endphp
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Бот: #{{ request('bot_id') }} - {{ $selectedBot->symbol ?? '' }}
                                    </span>
                                @endif
                                @if(request('symbol'))
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Символ: {{ request('symbol') }}
                                    </span>
                                @endif
                                @if(request('side'))
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Сторона: {{ request('side') }}
                                    </span>
                                @endif
                                @if(request('status'))
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Статус: {{ request('status') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Таблица сделок -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4 text-sm text-gray-600">
                        {{ __('Всего найдено') }}: {{ $trades->total() }} {{ __('сделок') }}
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Сторона</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Символ</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Количество</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Цена</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PnL</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Бот</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($trades as $trade)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $trade->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $trade->side === 'BUY' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $trade->side }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $trade->symbol }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ number_format($trade->quantity, 8) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">${{ number_format($trade->price, 2) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            {{ $trade->status === 'FILLED' ? 'bg-green-100 text-green-800' : 
                                               ($trade->status === 'FAILED' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">
                                            {{ $trade->status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium {{ $trade->realized_pnl > 0 ? 'text-green-600' : ($trade->realized_pnl < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                        @if($trade->realized_pnl)
                                            {{ number_format($trade->realized_pnl, 8) }} USDT
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        #{{ $trade->bot->id ?? '-' }} ({{ $trade->bot->symbol ?? '-' }})
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        {{ __('Сделки не найдены') }}
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Пагинация -->
                    @if($trades->hasPages())
                        <div class="mt-4">
                            {{ $trades->links() }}
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
