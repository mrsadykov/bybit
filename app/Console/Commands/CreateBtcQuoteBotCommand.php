<?php

namespace App\Console\Commands;

use App\Models\BtcQuoteBot;
use App\Models\ExchangeAccount;
use Illuminate\Console\Command;

class CreateBtcQuoteBotCommand extends Command
{
    protected $signature = 'btc-quote:create-bot 
                            {symbol : Пара (SOLBTC, ETHBTC, BNBBTC)}
                            {--account=1 : ID аккаунта биржи (OKX)}
                            {--position=0.0005 : Размер позиции в BTC на одну покупку}
                            {--timeframe=1h : Таймфрейм (1h, 5m, 15m, 4h)}
                            {--dry-run : Создать в режиме симуляции (без реальных ордеров)}
                            {--active : Сразу включить бота}';

    protected $description = 'Создать бота за BTC (торговля альтов за BTC на OKX)';

    public function handle(): int
    {
        $symbol = strtoupper($this->argument('symbol'));
        if (! preg_match('/^[A-Z]{2,10}BTC$/', $symbol)) {
            $this->error('Символ должен быть вида SOLBTC, ETHBTC, BNBBTC.');
            return self::FAILURE;
        }

        $accountId = (int) $this->option('account');
        $account = ExchangeAccount::find($accountId);
        if (! $account || $account->exchange !== 'okx') {
            $this->error('Аккаунт не найден или не OKX. Укажите --account=ID с exchange=okx.');
            return self::FAILURE;
        }

        $positionSizeBtc = (float) $this->option('position');
        $timeframe = $this->option('timeframe');
        $dryRun = $this->option('dry-run');
        $active = $this->option('active');

        if ($positionSizeBtc <= 0) {
            $this->error('Размер позиции (--position) должен быть больше 0.');
            return self::FAILURE;
        }

        $bot = BtcQuoteBot::create([
            'user_id' => $account->user_id,
            'exchange_account_id' => $account->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'strategy' => 'rsi_ema',
            'rsi_period' => 17,
            'ema_period' => 10,
            'rsi_buy_threshold' => null,
            'rsi_sell_threshold' => null,
            'position_size_btc' => $positionSizeBtc,
            'stop_loss_percent' => null,
            'take_profit_percent' => null,
            'is_active' => $active,
            'dry_run' => $dryRun,
            'last_trade_at' => null,
        ]);

        $this->info("Бот за BTC создан: #{$bot->id} {$bot->symbol}");
        $this->line("  Размер позиции: {$bot->position_size_btc} BTC");
        $this->line("  Таймфрейм: {$bot->timeframe}");
        $this->line('  Dry run: ' . ($bot->dry_run ? 'да' : 'нет') . ', активен: ' . ($bot->is_active ? 'да' : 'нет'));
        return self::SUCCESS;
    }
}
