<?php

namespace App\Console\Commands;

use App\Models\BtcQuoteBot;
use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use Illuminate\Console\Command;

class RestoreFuturesAndBtcQuoteBotsCommand extends Command
{
    protected $signature = 'bots:restore-futures-and-btc-quote
                            {--account= : ID аккаунта OKX (по умолчанию — первый OKX)}
                            {--futures= : Символы фьючерсов через запятую (по умолчанию BTCUSDT)}
                            {--btc-quote= : Пары за BTC через запятую (по умолчанию SOLBTC)}
                            {--dry-run : Создать в режиме симуляции}
                            {--active : Сразу включить ботов}';

    protected $description = 'Восстановить удалённых фьючерсных ботов и ботов за BTC (создаёт с дефолтными настройками)';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $account = $accountId
            ? ExchangeAccount::where('exchange', 'okx')->find($accountId)
            : ExchangeAccount::where('exchange', 'okx')->first();

        if (! $account) {
            $this->error('OKX аккаунт не найден. Создайте аккаунт: php artisan okx:create-account');
            return self::FAILURE;
        }

        $this->info("Используем аккаунт OKX #{$account->id} (user_id: {$account->user_id})");

        $futuresSymbols = $this->option('futures') ? array_map('trim', explode(',', $this->option('futures'))) : ['BTCUSDT'];
        $btcQuotePairs = $this->option('btc-quote') ? array_map('trim', explode(',', $this->option('btc-quote'))) : ['SOLBTC'];

        $dryRun = $this->option('dry-run');
        $active = $this->option('active');

        foreach ($futuresSymbols as $symbol) {
            $symbol = strtoupper($symbol);
            if (! preg_match('/^[A-Z]{2,10}USDT$/', $symbol)) {
                $this->warn("Пропуск фьючерса: символ должен быть вида BTCUSDT — {$symbol}");
                continue;
            }
            $bot = FuturesBot::create([
                'user_id' => $account->user_id,
                'exchange_account_id' => $account->id,
                'symbol' => $symbol,
                'timeframe' => '1h',
                'strategy' => 'rsi_ema',
                'rsi_period' => 17,
                'ema_period' => 10,
                'rsi_buy_threshold' => null,
                'rsi_sell_threshold' => null,
                'position_size_usdt' => 500,
                'leverage' => 2,
                'stop_loss_percent' => null,
                'take_profit_percent' => null,
                'max_daily_loss_usdt' => null,
                'max_losing_streak' => null,
                'is_active' => $active,
                'dry_run' => $dryRun,
                'last_trade_at' => null,
            ]);
            $this->info("Фьючерсный бот создан: #{$bot->id} {$bot->symbol}");
        }

        foreach ($btcQuotePairs as $symbol) {
            $symbol = strtoupper($symbol);
            if (! preg_match('/^[A-Z]{2,10}BTC$/', $symbol)) {
                $this->warn("Пропуск BTC-quote: символ должен быть вида SOLBTC — {$symbol}");
                continue;
            }
            $bot = BtcQuoteBot::create([
                'user_id' => $account->user_id,
                'exchange_account_id' => $account->id,
                'symbol' => $symbol,
                'timeframe' => '1h',
                'strategy' => 'rsi_ema',
                'rsi_period' => 17,
                'ema_period' => 10,
                'rsi_buy_threshold' => null,
                'rsi_sell_threshold' => null,
                'position_size_btc' => 0.0005,
                'stop_loss_percent' => null,
                'take_profit_percent' => null,
                'max_daily_loss_btc' => null,
                'max_losing_streak' => null,
                'is_active' => $active,
                'dry_run' => $dryRun,
                'last_trade_at' => null,
            ]);
            $this->info("Бот за BTC создан: #{$bot->id} {$bot->symbol}");
        }

        $this->newLine();
        $this->info('Готово. Настройки можно изменить в интерфейсе (Фьючерсы / Боты за BTC).');
        return self::SUCCESS;
    }
}
