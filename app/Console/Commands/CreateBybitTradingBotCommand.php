<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\TradingBot;
use App\Models\User;
use Illuminate\Console\Command;

class CreateBybitTradingBotCommand extends Command
{
    protected $signature = 'bybit-bot:create
        {symbol : BTCUSDT}
        {timeframe : 5m}
        {strategy : rsi_ema}
        {position_size : 0.00006}'
    ;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::query()
            ->where('email', config('app.admin.email'))
            ->firstOrFail();

        $account = ExchangeAccount::query()
            ->where('user_id', $user->id)
            ->where('exchange', 'bybit')
            ->firstOrFail();

        $bot = TradingBot::query()
            ->create([
                'user_id' => $user->id,
                'exchange_account_id' => $account->id,
                'symbol' => strtoupper($this->argument('symbol')),
                'timeframe' => $this->argument('timeframe'),
                'strategy' => $this->argument('strategy'),
                'position_size' => (float) $this->argument('position_size'),
                'is_active' => false
            ]);

        $this->info("TradingBot: #{$bot->id} created.");
        $this->info("Symbol: {$bot->symbol}.");
        $this->info("Timeframe: {$bot->timeframe}.");
        $this->info("Strategy: {$bot->strategy}.");
        $this->info("Position: {$bot->position_size}.");

        return self::SUCCESS;
    }
}
