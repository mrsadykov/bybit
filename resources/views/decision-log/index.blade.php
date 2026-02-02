<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('decision_log.title') }}
            </h2>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">
                ← {{ __('common.dashboard') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-4">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('decision_log.filters') }}</h3>
                        @if(request()->hasAny(['bot', 'bot_type', 'bot_id', 'symbol', 'signal', 'date_from', 'date_to']))
                            <a href="{{ route('decision-log.index') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                ✕ {{ __('trades.reset_all') }}
                            </a>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('decision-log.index') }}" id="filtersForm" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.bot_type') }}</label>
                            <select name="bot_type" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('decision_log.all_types') }}</option>
                                <option value="spot" {{ request('bot_type') === 'spot' ? 'selected' : '' }}>{{ __('decision_log.spot') }}</option>
                                <option value="futures" {{ request('bot_type') === 'futures' ? 'selected' : '' }}>{{ __('decision_log.futures') }}</option>
                                <option value="btc_quote" {{ request('bot_type') === 'btc_quote' ? 'selected' : '' }}>{{ __('decision_log.btc_quote') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.bot') }}</label>
                            <select name="bot" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('decision_log.all_bots') }}</option>
                                @foreach($spotBots as $b)
                                    <option value="spot:{{ $b->id }}" {{ request('bot') === 'spot:'.$b->id ? 'selected' : '' }}>Spot #{{ $b->id }} {{ $b->symbol }}</option>
                                @endforeach
                                @foreach($futuresBots as $b)
                                    <option value="futures:{{ $b->id }}" {{ request('bot') === 'futures:'.$b->id ? 'selected' : '' }}>Futures #{{ $b->id }} {{ $b->symbol }}</option>
                                @endforeach
                                @foreach($btcQuoteBots as $b)
                                    <option value="btc_quote:{{ $b->id }}" {{ request('bot') === 'btc_quote:'.$b->id ? 'selected' : '' }}>BTC-quote #{{ $b->id }} {{ $b->symbol }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.symbol') }}</label>
                            <select name="symbol" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('decision_log.all_symbols') }}</option>
                                @foreach($symbols as $s)
                                    <option value="{{ $s }}" {{ request('symbol') === $s ? 'selected' : '' }}>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.signal') }}</label>
                            <select name="signal" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('decision_log.all_signals') }}</option>
                                <option value="HOLD" {{ request('signal') === 'HOLD' ? 'selected' : '' }}>HOLD</option>
                                <option value="BUY" {{ request('signal') === 'BUY' ? 'selected' : '' }}>BUY</option>
                                <option value="SELL" {{ request('signal') === 'SELL' ? 'selected' : '' }}>SELL</option>
                                <option value="SKIP" {{ request('signal') === 'SKIP' ? 'selected' : '' }}>SKIP</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.date_from') }}</label>
                            <input type="date" name="date_from" value="{{ request('date_from') }}" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1.5">{{ __('decision_log.date_to') }}</label>
                            <input type="date" name="date_to" value="{{ request('date_to') }}" onchange="document.getElementById('filtersForm').submit();" class="w-full text-sm rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4 text-sm text-gray-600">
                        {{ __('decision_log.total_found') }}: {{ $logs->total() }}
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.date') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.type') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.symbol') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.signal') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.price') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">RSI</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">EMA</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('decision_log.reason') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($logs as $log)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $log->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                        @if($log->bot_type === 'spot')
                                            <span class="text-green-700">Spot</span>
                                        @elseif($log->bot_type === 'futures')
                                            <span class="text-amber-700">Futures</span>
                                        @else
                                            <span class="text-blue-700">BTC-quote</span>
                                        @endif
                                        #{{ $log->bot_id }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $log->symbol }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($log->signal === 'BUY')
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">BUY</span>
                                        @elseif($log->signal === 'SELL')
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">SELL</span>
                                        @elseif($log->signal === 'SKIP')
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">SKIP</span>
                                        @else
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-slate-100 text-slate-800">HOLD</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        @if($log->price !== null) {{ number_format((float)$log->price, 8) }} @else — @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $log->rsi !== null ? round($log->rsi, 2) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $log->ema !== null ? round($log->ema, 4) : '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="{{ $log->reason }}">{{ $log->reason ?? '—' }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        {{ __('decision_log.no_logs') }}
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($logs->hasPages())
                        <div class="mt-4">
                            {{ $logs->links() }}
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
