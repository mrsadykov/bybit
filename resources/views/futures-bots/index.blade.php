<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('futures.title') }}
            </h2>
            <a href="{{ route('futures-bots.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                {{ __('futures.create_bot') }}
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
                                        <p class="text-sm text-gray-500">{{ __('futures.bot_number', ['id' => $bot->id]) }}</p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        @if($bot->is_active)
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{ __('futures.active') }}</span>
                                        @else
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">{{ __('futures.inactive') }}</span>
                                        @endif
                                        @if($bot->dry_run)
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{ __('futures.dry_run') }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-2 text-sm mb-4">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('futures.strategy') }}:</span>
                                        <span class="font-medium">{{ $bot->strategy }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('futures.timeframe') }}:</span>
                                        <span class="font-medium">{{ $bot->timeframe }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('futures.position_size_usdt') }}:</span>
                                        <span class="font-medium">{{ number_format($bot->position_size_usdt, 2) }} USDT</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('futures.leverage') }}:</span>
                                        <span class="font-medium">{{ $bot->leverage }}x</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ __('futures.exchange') }}:</span>
                                        <span class="font-medium">{{ strtoupper($bot->exchangeAccount->exchange ?? 'N/A') }}</span>
                                    </div>
                                </div>

                                <div class="flex justify-between items-center gap-2">
                                    <div class="flex gap-2">
                                        <a href="{{ route('futures-bots.show', $bot) }}" class="bg-blue-500 hover:bg-blue-600 text-white text-center font-medium py-1.5 px-3 rounded-md text-xs">
                                            {{ __('futures.view') }}
                                        </a>
                                        <form action="{{ route('futures-bots.toggle-active', $bot) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="{{ $bot->is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' }} text-white font-medium py-1.5 px-3 rounded-md text-xs">
                                                {{ $bot->is_active ? __('futures.deactivate') : __('futures.activate') }}
                                            </button>
                                        </form>
                                    </div>
                                    <a href="{{ route('futures-bots.edit', $bot) }}" class="bg-gray-500 hover:bg-gray-600 text-white text-center font-medium py-1.5 px-3 rounded-md text-xs">
                                        {{ __('futures.edit') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-center">
                        <p class="text-gray-500 mb-4">{{ __('futures.no_bots') }}</p>
                        <a href="{{ route('futures-bots.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-block">
                            {{ __('futures.create_first_bot') }}
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
