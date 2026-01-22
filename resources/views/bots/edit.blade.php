<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('bots.edit_bot') }} #{{ $bot->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form action="{{ route('bots.update', $bot) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <!-- Exchange Account -->
                        <div class="mb-4">
                            <label for="exchange_account_id" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.exchange_account') }} <span class="text-red-500">{{ __('common.required') }}</span>
                            </label>
                            <select name="exchange_account_id" id="exchange_account_id" required
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" {{ $bot->exchange_account_id == $account->id ? 'selected' : '' }}>
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
                            <input type="text" name="symbol" id="symbol" value="{{ old('symbol', $bot->symbol) }}" required
                                placeholder="BTCUSDT" pattern="^[A-Z]{2,10}USDT$"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                                <option value="1" {{ $bot->timeframe == '1' ? 'selected' : '' }}>{{ __('bots.timeframe_1') }}</option>
                                <option value="3" {{ $bot->timeframe == '3' ? 'selected' : '' }}>{{ __('bots.timeframe_3') }}</option>
                                <option value="5" {{ $bot->timeframe == '5' ? 'selected' : '' }}>{{ __('bots.timeframe_5') }}</option>
                                <option value="15" {{ $bot->timeframe == '15' ? 'selected' : '' }}>{{ __('bots.timeframe_15') }}</option>
                                <option value="30" {{ $bot->timeframe == '30' ? 'selected' : '' }}>{{ __('bots.timeframe_30') }}</option>
                                <option value="60" {{ $bot->timeframe == '60' ? 'selected' : '' }}>{{ __('bots.timeframe_60') }}</option>
                                <option value="120" {{ $bot->timeframe == '120' ? 'selected' : '' }}>{{ __('bots.timeframe_120') }}</option>
                                <option value="240" {{ $bot->timeframe == '240' ? 'selected' : '' }}>{{ __('bots.timeframe_240') }}</option>
                                <option value="D" {{ $bot->timeframe == 'D' ? 'selected' : '' }}>{{ __('bots.timeframe_D') }}</option>
                                <option value="W" {{ $bot->timeframe == 'W' ? 'selected' : '' }}>{{ __('bots.timeframe_W') }}</option>
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
                                <option value="rsi_ema" {{ $bot->strategy == 'rsi_ema' ? 'selected' : '' }}>RSI + EMA</option>
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
                                value="{{ old('position_size', $bot->position_size) }}" required step="0.00000001" min="1"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('position_size')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- RSI Period -->
                        <div class="mb-4">
                            <label for="rsi_period" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.rsi_period') }}
                            </label>
                            <input type="number" name="rsi_period" id="rsi_period" 
                                value="{{ old('rsi_period', $bot->rsi_period) }}" step="1" min="2" max="100"
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
                                value="{{ old('ema_period', $bot->ema_period) }}" step="1" min="2" max="200"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('ema_period')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Stop Loss -->
                        <div class="mb-4">
                            <label for="stop_loss_percent" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('bots.stop_loss_percent') }}
                            </label>
                            <input type="number" name="stop_loss_percent" id="stop_loss_percent" 
                                value="{{ old('stop_loss_percent', $bot->stop_loss_percent) }}" step="0.01" min="0" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                                value="{{ old('take_profit_percent', $bot->take_profit_percent) }}" step="0.01" min="0" max="100"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('take_profit_percent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Dry Run -->
                        <div class="mb-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="dry_run" value="1" 
                                    {{ old('dry_run', $bot->dry_run) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('bots.dry_run_mode') }}</span>
                            </label>
                        </div>

                        <!-- Is Active -->
                        <div class="mb-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="is_active" value="1" 
                                    {{ old('is_active', $bot->is_active) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <span class="ml-2 text-sm text-gray-700">{{ __('bots.is_active') }}</span>
                            </label>
                        </div>

                        <div class="flex items-center justify-end gap-4 mt-6">
                            <a href="{{ route('bots.show', $bot) }}" 
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('bots.cancel') }}
                            </a>
                            <button type="submit" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                {{ __('bots.update') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
