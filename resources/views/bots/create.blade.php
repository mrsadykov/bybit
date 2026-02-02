<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('bots.create_bot') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form action="{{ route('bots.store') }}" method="POST">
                        @csrf

                        <!-- Exchange Account -->
                        <div class="mb-4">
                            <label for="exchange_account_id" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.exchange_account') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <select name="exchange_account_id" id="exchange_account_id" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Выберите аккаунт</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ strtoupper($account->exchange) }} 
                                        {{ $account->is_testnet ? '(Testnet)' : '(Production)' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('exchange_account_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Symbol -->
                        <div class="mb-4">
                            <label for="symbol" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.trading_pair') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <input type="text" name="symbol" id="symbol" value="{{ old('symbol') }}" required
                                placeholder="BTCUSDT" pattern="^[A-Z]{2,10}USDT$"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.symbol_format') }}</p>
                            @error('symbol')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Timeframe -->
                        <div class="mb-4">
                            <label for="timeframe" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.timeframe') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <select name="timeframe" id="timeframe" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="1">{{ __('bots.timeframe_1') }}</option>
                                <option value="3">{{ __('bots.timeframe_3') }}</option>
                                <option value="5" selected>{{ __('bots.timeframe_5') }}</option>
                                <option value="15">{{ __('bots.timeframe_15') }}</option>
                                <option value="30">{{ __('bots.timeframe_30') }}</option>
                                <option value="60">{{ __('bots.timeframe_60') }}</option>
                                <option value="120">{{ __('bots.timeframe_120') }}</option>
                                <option value="240">{{ __('bots.timeframe_240') }}</option>
                                <option value="D">{{ __('bots.timeframe_D') }}</option>
                                <option value="W">{{ __('bots.timeframe_W') }}</option>
                            </select>
                            @error('timeframe')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Strategy -->
                        <div class="mb-4">
                            <label for="strategy" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.strategy') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <select name="strategy" id="strategy" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="rsi_ema" selected>RSI + EMA</option>
                            </select>
                            @error('strategy')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Position Size -->
                        <div class="mb-4">
                            <label for="position_size" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.position_size_usdt') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <input type="number" name="position_size" id="position_size" 
                                value="{{ old('position_size', '10') }}" required step="0.00000001" min="1"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.min_position_size') }}</p>
                            @error('position_size')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RSI Period -->
                        <div class="mb-4">
                            <label for="rsi_period" class="block text-sm font-medium text-gray-700 mb-2">
                                RSI Период
                            </label>
                            <input type="number" name="rsi_period" id="rsi_period" 
                                value="{{ old('rsi_period', '17') }}" step="1" min="2" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('rsi_period')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- EMA Period -->
                        <div class="mb-4">
                            <label for="ema_period" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.ema_period') }}
                            </label>
                            <input type="number" name="ema_period" id="ema_period" 
                                value="{{ old('ema_period', '10') }}" step="1" min="2" max="200"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('ema_period')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RSI Buy / Sell Thresholds -->
                        <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="rsi_buy_threshold" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('bots.rsi_buy_threshold') }}
                                </label>
                                <input type="number" name="rsi_buy_threshold" id="rsi_buy_threshold" 
                                    value="{{ old('rsi_buy_threshold') }}" step="0.01" min="20" max="80" placeholder="40"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error('rsi_buy_threshold')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="rsi_sell_threshold" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('bots.rsi_sell_threshold') }}
                                </label>
                                <input type="number" name="rsi_sell_threshold" id="rsi_sell_threshold" 
                                    value="{{ old('rsi_sell_threshold') }}" step="0.01" min="20" max="80" placeholder="60"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error('rsi_sell_threshold')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <p class="mb-4 text-sm text-gray-500">{{ __('bots.rsi_thresholds_help') }}</p>

                        <!-- Stop Loss -->
                        <div class="mb-4">
                            <label for="stop_loss_percent" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.stop_loss_percent') }}
                            </label>
                            <input type="number" name="stop_loss_percent" id="stop_loss_percent" 
                                value="{{ old('stop_loss_percent') }}" step="0.01" min="0" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.stop_loss_help') }}</p>
                            @error('stop_loss_percent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Take Profit -->
                        <div class="mb-4">
                            <label for="take_profit_percent" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.take_profit_percent') }}
                            </label>
                            <input type="number" name="take_profit_percent" id="take_profit_percent" 
                                value="{{ old('take_profit_percent') }}" step="0.01" min="0" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.take_profit_help') }}</p>
                            @error('take_profit_percent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Max Daily Loss (USDT) -->
                        <div class="mb-4">
                            <label for="max_daily_loss_usdt" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.max_daily_loss_usdt') }}
                            </label>
                            <input type="number" name="max_daily_loss_usdt" id="max_daily_loss_usdt"
                                value="{{ old('max_daily_loss_usdt') }}" step="0.01" min="0"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ __('bots.max_daily_loss_placeholder') }}">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.max_daily_loss_help') }}</p>
                            @error('max_daily_loss_usdt')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Max Drawdown (%) -->
                        <div class="mb-4">
                            <label for="max_drawdown_percent" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.max_drawdown_percent') }}
                            </label>
                            <input type="number" name="max_drawdown_percent" id="max_drawdown_percent"
                                value="{{ old('max_drawdown_percent') }}" step="0.01" min="0" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ __('bots.max_drawdown_placeholder') }}">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.max_drawdown_help') }}</p>
                            @error('max_drawdown_percent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Max Losing Streak -->
                        <div class="mb-4">
                            <label for="max_losing_streak" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.max_losing_streak') }}
                            </label>
                            <input type="number" name="max_losing_streak" id="max_losing_streak"
                                value="{{ old('max_losing_streak') }}" step="1" min="1" max="20"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ __('bots.max_losing_streak_placeholder') }}">
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.max_losing_streak_help') }}</p>
                            @error('max_losing_streak')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Use MACD filter -->
                        <div class="mb-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="use_macd_filter" value="1"
                                    {{ old('use_macd_filter') ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('bots.use_macd_filter') }}</span>
                            </label>
                            <p class="mt-1 text-sm text-gray-500">{{ __('bots.use_macd_filter_help') }}</p>
                        </div>

                        <!-- Dry Run -->
                        <div class="mb-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="dry_run" value="1"
                                    {{ old('dry_run') ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('bots.dry_run_mode') }}</span>
                            </label>
                        </div>

                        <div class="flex items-center justify-end gap-4 mt-6">
                            <a href="{{ route('bots.index') }}" 
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('bots.cancel') }}
                            </a>
                            <button type="submit" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('bots.create') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
