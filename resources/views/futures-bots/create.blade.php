<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('futures.create_bot') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form action="{{ route('futures-bots.store') }}" method="POST">
                        @csrf

                        <div class="mb-4">
                            <label for="exchange_account_id" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.exchange_account') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="exchange_account_id" id="exchange_account_id" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">—</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" {{ old('exchange_account_id') == $account->id ? 'selected' : '' }}>
                                        OKX {{ $account->is_testnet ? '(Testnet)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('exchange_account_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="symbol" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.trading_pair') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="symbol" id="symbol" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach(config('futures.symbols_for_form', ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT']) as $sym)
                                    <option value="{{ $sym }}" {{ old('symbol', 'SOLUSDT') == $sym ? 'selected' : '' }}>{{ $sym }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">{{ __('futures.pairs_help') }}</p>
                            @error('symbol')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="timeframe" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.timeframe') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="timeframe" id="timeframe" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="5m" {{ old('timeframe') == '5m' ? 'selected' : '' }}>5m</option>
                                <option value="15m" {{ old('timeframe') == '15m' ? 'selected' : '' }}>15m</option>
                                <option value="1h" {{ old('timeframe', '1h') == '1h' ? 'selected' : '' }}>1h</option>
                                <option value="4h" {{ old('timeframe') == '4h' ? 'selected' : '' }}>4h</option>
                            </select>
                            @error('timeframe')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="strategy" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.strategy') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="strategy" id="strategy" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="rsi_ema" selected>RSI + EMA</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="position_size_usdt" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.position_size_usdt') }} <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="position_size_usdt" id="position_size_usdt"
                                value="{{ old('position_size_usdt', '500') }}" required step="1" min="1"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('futures.position_size_help') }}</p>
                            @error('position_size_usdt')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="leverage" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('futures.leverage') }} <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="leverage" id="leverage"
                                value="{{ old('leverage', '2') }}" required min="1" max="{{ (int) config('futures.max_leverage', 125) }}"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('futures.leverage_help') }}</p>
                            <p class="mt-1 text-sm text-amber-600">{{ __('futures.leverage_high_risk') }}</p>
                            @error('leverage')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="rsi_period" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.rsi_period') }}</label>
                                <input type="number" name="rsi_period" id="rsi_period" value="{{ old('rsi_period', '17') }}" min="2" max="100"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="ema_period" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.ema_period') }}</label>
                                <input type="number" name="ema_period" id="ema_period" value="{{ old('ema_period', '10') }}" min="2" max="200"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="rsi_buy_threshold" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.rsi_buy_threshold') }}</label>
                                <input type="number" name="rsi_buy_threshold" id="rsi_buy_threshold" value="{{ old('rsi_buy_threshold') }}" step="0.01" min="20" max="80" placeholder="40"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="rsi_sell_threshold" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.rsi_sell_threshold') }}</label>
                                <input type="number" name="rsi_sell_threshold" id="rsi_sell_threshold" value="{{ old('rsi_sell_threshold') }}" step="0.01" min="20" max="80" placeholder="60"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>
                        @error('rsi_sell_threshold')
                            <p class="text-sm text-red-600 mb-2">{{ $message }}</p>
                        @enderror

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="stop_loss_percent" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.stop_loss_percent') }}</label>
                                <input type="number" name="stop_loss_percent" id="stop_loss_percent" value="{{ old('stop_loss_percent') }}" step="0.01" min="0" max="100" placeholder="—"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="take_profit_percent" class="block text-sm font-medium text-gray-700 mb-2">{{ __('futures.take_profit_percent') }}</label>
                                <input type="number" name="take_profit_percent" id="take_profit_percent" value="{{ old('take_profit_percent') }}" step="0.01" min="0" max="100" placeholder="—"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 mb-6">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="dry_run" value="1" {{ old('dry_run', true) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('futures.dry_run_mode') }}</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_active" value="1" {{ old('is_active') ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('futures.is_active') }}</span>
                            </label>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('futures.create') }}
                            </button>
                            <a href="{{ route('futures-bots.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('futures.cancel') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
