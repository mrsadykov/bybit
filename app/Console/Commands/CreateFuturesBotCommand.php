<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use Illuminate\Console\Command;

class CreateFuturesBotCommand extends Command
{
    protected $signature = 'futures:create-bot 
                            {symbol : Символ (BTCUSDT, ETHUSDT, SOLUSDT, BNBUSDT)}
                            {--account=1 : ID аккаунта биржи (OKX)}
                            {--position=500 : Размер позиции в USDT (для 1 контракта BTC при 2x нужно ~450–500)}
                            {--leverage=2 : Плечо (2–3)}
                            {--timeframe=1h : Таймфрейм (1h, 5m, 15m)}
                            {--dry-run : Создать в режиме симуляции (без реальных ордеров)}
                            {--active : Сразу включить бота}';

    protected $description = 'Создать фьючерсного бота (OKX perpetual swap)';

    public function handle(): int
    {
        $symbol = strtoupper($this->argument('symbol'));
        if (! preg_match('/^[A-Z]{2,10}USDT$/', $symbol)) {
            $this->error('Символ должен быть вида BTCUSDT, ETHUSDT, ...');
            return self::FAILURE;
        }

        $accountId = (int) $this->option('account');
        $account = ExchangeAccount::find($accountId);
        if (! $account || $account->exchange !== 'okx') {
            $this->error('Аккаунт не найден или не OKX. Укажите --account=ID с exchange=okx.');
            return self::FAILURE;
        }

        $positionSizeUsdt = (float) $this->option('position');
        $leverage = (int) $this->option('leverage');
        $timeframe = $this->option('timeframe');
        $dryRun = $this->option('dry-run');
        $active = $this->option('active');

        $bot = FuturesBot::create([
            'user_id' => $account->user_id,
            'exchange_account_id' => $account->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'strategy' => 'rsi_ema',
            'rsi_period' => 17,
            'ema_period' => 10,
            'rsi_buy_threshold' => null,
            'rsi_sell_threshold' => null,
            'position_size_usdt' => $positionSizeUsdt,
            'leverage' => max(1, min((int) config('futures.max_leverage', 125), $leverage)),
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'is_active' => $active,
            'dry_run' => $dryRun,
            'last_trade_at' => null,
        ]);

        $this->info("Фьючерсный бот создан: #{$bot->id} {$bot->symbol}");
        $this->line("  Позиция: {$bot->position_size_usdt} USDT, плечо: {$bot->leverage}x");
        $this->line('  Dry run: ' . ($bot->dry_run ? 'да' : 'нет') . ', активен: ' . ($bot->is_active ? 'да' : 'нет'));
        return self::SUCCESS;
    }
}
