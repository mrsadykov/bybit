<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('bots.title') }}
            </h2>
            <a href="{{ route('bots.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                {{ __('bots.create_bot') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if($bots->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    @foreach($bots as $bot)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border {{ $bot->is_active ? 'border-green-300' : 'border-gray-300' }} mb-4">
                            <div class="p-6">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900">{{ $bot->symbol }}</h3>
                                        <p class="text-sm text-gray-500">{{ __('bots.bot_number', ['id' => $bot->id]) }}</p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        @if($bot->is_active)
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{ __('bots.active') }}</span>
                                        @else
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">{{ __('bots.inactive') }}</span>
                                        @endif
                                        @if($bot->dry_run)
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{ __('bots.dry_run') }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-2 text-sm mb-4">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('bots.strategy') }}:</span>
                                        <span class="font-medium">{{ $bot->strategy }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('bots.timeframe') }}:</span>
                                        <span class="font-medium">{{ $bot->timeframe }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('bots.position_size') }}:</span>
                                        <span class="font-medium">{{ number_format($bot->position_size, 8) }} USDT</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('bots.exchange') }}:</span>
                                        <span class="font-medium">{{ strtoupper($bot->exchangeAccount->exchange ?? 'N/A') }}</span>
                                    </div>
                                    @if($bot->stop_loss_percent || $bot->take_profit_percent)
                                        <div class="pt-2 border-t border-gray-200 mt-2">
                                            @if($bot->stop_loss_percent)
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">{{ __('bots.stop_loss') }}:</span>
                                                    <span class="font-medium text-red-600">-{{ number_format($bot->stop_loss_percent, 2) }}%</span>
                                                </div>
                                            @endif
                                            @if($bot->take_profit_percent)
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">{{ __('bots.take_profit') }}:</span>
                                                    <span class="font-medium text-green-600">+{{ number_format($bot->take_profit_percent, 2) }}%</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="flex justify-between items-center gap-2">
                                    <div class="flex gap-2">
                                        <a href="{{ route('bots.show', $bot) }}" class="bg-blue-500 hover:bg-blue-600 text-white text-center font-medium py-1.5 px-3 rounded-md text-xs transition-colors duration-200">
                                            {{ __('bots.view') }}
                                        </a>
                                        <form action="{{ route('bots.toggle-active', $bot) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="{{ $bot->is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' }} text-white font-medium py-1.5 px-3 rounded-md text-xs transition-colors duration-200">
                                                {{ $bot->is_active ? __('bots.deactivate') : __('bots.activate') }}
                                            </button>
                                        </form>
                                    </div>
                                    <a href="{{ route('bots.edit', $bot) }}" class="bg-gray-500 hover:bg-gray-600 text-white text-center font-medium py-1.5 px-3 rounded-md text-xs transition-colors duration-200">
                                        {{ __('bots.edit') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-center">
                        <p class="text-gray-500 mb-4">{{ __('bots.no_bots') }}</p>
                        <a href="{{ route('bots.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-block">
                            {{ __('bots.create_first_bot') }}
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
