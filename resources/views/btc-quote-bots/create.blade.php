<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('btc_quote.create_bot') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form action="{{ route('btc-quote-bots.store') }}" method="POST">
                        @csrf

                        <div class="mb-4">
                            <label for="exchange_account_id" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('btc_quote.exchange_account') }} <span class="text-red-500">*</span>
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
                                {{ __('btc_quote.trading_pair') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="symbol" id="symbol" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach(config('btc_quote.symbols_for_form', ['SOLBTC', 'ETHBTC', 'BNBBTC']) as $sym)
                                    <option value="{{ $sym }}" {{ old('symbol', 'SOLBTC') == $sym ? 'selected' : '' }}>{{ $sym }}</option>
                                @endforeach
                            </select>
                            @error('symbol')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="timeframe" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('btc_quote.timeframe') }} <span class="text-red-500">*</span>
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
                                {{ __('btc_quote.strategy') }} <span class="text-red-500">*</span>
                            </label>
                            <select name="strategy" id="strategy" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="rsi_ema" selected>RSI + EMA</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="position_size_btc" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('btc_quote.position_size_btc') }} <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="position_size_btc" id="position_size_btc"
                                value="{{ old('position_size_btc', '0.001') }}" required step="0.00001" min="0.00001"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('btc_quote.position_size_help') }}</p>
                            @error('position_size_btc')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="rsi_period" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.rsi_period') }}</label>
                                <input type="number" name="rsi_period" id="rsi_period" value="{{ old('rsi_period', '17') }}" min="2" max="100"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="ema_period" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.ema_period') }}</label>
                                <input type="number" name="ema_period" id="ema_period" value="{{ old('ema_period', '10') }}" min="2" max="200"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="rsi_buy_threshold" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.rsi_buy_threshold') }}</label>
                                <input type="number" name="rsi_buy_threshold" id="rsi_buy_threshold" value="{{ old('rsi_buy_threshold') }}" step="0.01" min="20" max="80" placeholder="40"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="rsi_sell_threshold" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.rsi_sell_threshold') }}</label>
                                <input type="number" name="rsi_sell_threshold" id="rsi_sell_threshold" value="{{ old('rsi_sell_threshold') }}" step="0.01" min="20" max="80" placeholder="60"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>
                        @error('rsi_sell_threshold')
                            <p class="text-sm text-red-600 mb-2">{{ $message }}</p>
                        @enderror

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="stop_loss_percent" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.stop_loss_percent') }}</label>
                                <input type="number" name="stop_loss_percent" id="stop_loss_percent" value="{{ old('stop_loss_percent') }}" step="0.01" min="0" max="100" placeholder="—"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="take_profit_percent" class="block text-sm font-medium text-gray-700 mb-2">{{ __('btc_quote.take_profit_percent') }}</label>
                                <input type="number" name="take_profit_percent" id="take_profit_percent" value="{{ old('take_profit_percent') }}" step="0.01" min="0" max="100" placeholder="—"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 mb-6">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="dry_run" value="1" {{ old('dry_run', true) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('btc_quote.dry_run_mode') }}</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_active" value="1" {{ old('is_active') ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('btc_quote.is_active') }}</span>
                            </label>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('btc_quote.create') }}
                            </button>
                            <a href="{{ route('btc-quote-bots.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('btc_quote.cancel') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
