<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\TradingBot;
use App\Models\User;
use Illuminate\Console\Command;

class CreateOkxTradingBotCommand extends Command
{
    protected $signature = 'okx-bot:create
        {symbol : Trading pair, e.g. BTCUSDT}
        {timeframe : Timeframe, e.g. 5m}
        {strategy : Strategy name, e.g. rsi_ema}
        {position_size : Position size in USDT, e.g. 10}
        {--stop-loss= : Stop-Loss процент (например: 5.0 = продать при падении на 5%)}
        {--take-profit= : Take-Profit процент (например: 10.0 = продать при росте на 10%)}';

    protected $description = 'Create a new OKX trading bot';

    public function handle()
    {
        $user = User::query()
            ->where('email', config('app.admin.email'))
            ->firstOrFail();

        $account = ExchangeAccount::query()
            ->where('user_id', $user->id)
            ->where('exchange', 'okx')
            ->first();

        if (!$account) {
            $this->error('OKX exchange account not found. Run: php artisan create-okx-account');
            return self::FAILURE;
        }

        $symbol = strtoupper($this->argument('symbol'));
        $timeframe = $this->argument('timeframe');
        $strategy = $this->argument('strategy');
        $positionSize = (float) $this->argument('position_size');
        $stopLoss = $this->option('stop-loss') ? (float) $this->option('stop-loss') : null;
        $takeProfit = $this->option('take-profit') ? (float) $this->option('take-profit') : null;

        // Валидация position size
        if ($positionSize <= 0) {
            $this->error('Position size must be greater than 0');
            return self::FAILURE;
        }

        // Валидация минимальной суммы
        $minNotional = config('trading.min_notional_usdt', 1);
        if ($positionSize < $minNotional) {
            $this->error("Position size must be at least {$minNotional} USDT");
            return self::FAILURE;
        }

        // Валидация формата символа (должен быть в формате BTCUSDT, ETHUSDT и т.д.)
        if (!preg_match('/^[A-Z]{2,10}USDT$/', $symbol)) {
            $this->error('Invalid symbol format. Expected format: BTCUSDT, ETHUSDT, etc.');
            return self::FAILURE;
        }

        // Валидация таймфрейма
        $validTimeframes = ['1', '3', '5', '15', '30', '60', '120', '240', '360', '720', 'D', 'W', 'M'];
        $timeframeValue = rtrim($timeframe, 'mh'); // Убираем 'm' или 'h' для проверки
        
        if (!in_array($timeframeValue, $validTimeframes) && !in_array($timeframe, $validTimeframes)) {
            $this->warn("Warning: Timeframe '{$timeframe}' may not be valid. Valid: " . implode(', ', $validTimeframes));
            // Не блокируем, но предупреждаем
        }

        // Валидация стратегии
        $validStrategies = ['rsi_ema']; // Можно расширить
        if (!in_array($strategy, $validStrategies)) {
            $this->warn("Warning: Strategy '{$strategy}' is not in the list of known strategies");
            // Не блокируем, но предупреждаем
        }

        // Валидация Stop-Loss
        if ($stopLoss !== null) {
            if ($stopLoss <= 0 || $stopLoss > 100) {
                $this->error('Stop-Loss must be between 0 and 100');
                return self::FAILURE;
            }
        }

        // Валидация Take-Profit
        if ($takeProfit !== null) {
            if ($takeProfit <= 0 || $takeProfit > 100) {
                $this->error('Take-Profit must be between 0 and 100');
                return self::FAILURE;
            }
        }

        $bot = TradingBot::query()
            ->create([
                'user_id' => $user->id,
                'exchange_account_id' => $account->id,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'strategy' => $strategy,
                'position_size' => $positionSize,
                'stop_loss_percent' => $stopLoss,
                'take_profit_percent' => $takeProfit,
                'is_active' => false
            ]);

        $this->info("✅ OKX TradingBot created: #{$bot->id}");
        $this->line("   Symbol: {$bot->symbol}");
        $this->line("   Timeframe: {$bot->timeframe}");
        $this->line("   Strategy: {$bot->strategy}");
        $this->line("   Position size: {$bot->position_size} USDT");
        
        if ($bot->stop_loss_percent) {
            $this->line("   Stop-Loss: -{$bot->stop_loss_percent}%");
        }
        
        if ($bot->take_profit_percent) {
            $this->line("   Take-Profit: +{$bot->take_profit_percent}%");
        }
        
        $this->line("   Status: Inactive (use tinker to activate)");
        $this->line('');
        $this->line("To activate the bot:");
        $this->line("  php artisan tinker");
        $this->line("  \$bot = \\App\\Models\\TradingBot::find({$bot->id});");
        $this->line("  \$bot->is_active = true;");
        $this->line("  \$bot->save();");

        return self::SUCCESS;
    }
}
