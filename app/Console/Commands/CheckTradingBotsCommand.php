<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use Illuminate\Console\Command;

class CheckTradingBotsCommand extends Command
{
    protected $signature = 'bots:check';
    protected $description = 'Check trading bots data integrity';

    public function handle(): int
    {
        $this->info('Checking trading bots...');
        $this->line('');

        $bots = TradingBot::with(['user', 'exchangeAccount'])->get();

        if ($bots->isEmpty()) {
            $this->warn('No trading bots found');
            return self::SUCCESS;
        }

        $this->info("Found {$bots->count()} trading bot(s)");
        $this->line('');

        $issues = [];
        $valid = 0;

        foreach ($bots as $bot) {
            $this->line(str_repeat('-', 60));
            $this->info("Bot #{$bot->id}");
            
            $botIssues = [];
            
            // Проверка обязательных полей
            if (empty($bot->user_id)) {
                $botIssues[] = '❌ user_id is empty';
            } else {
                $this->line("  User ID: {$bot->user_id}");
            }

            if (empty($bot->exchange_account_id)) {
                $botIssues[] = '❌ exchange_account_id is empty';
            } else {
                $this->line("  Exchange Account ID: {$bot->exchange_account_id}");
                
                if ($bot->exchangeAccount) {
                    $type = $bot->exchangeAccount->is_testnet ? 'Testnet' : 'Production';
                    $this->line("  Exchange Account Type: {$type}");
                } else {
                    $botIssues[] = '⚠️  Exchange account not found (may be deleted)';
                }
            }

            if (empty($bot->symbol)) {
                $botIssues[] = '❌ symbol is empty';
            } else {
                $this->line("  Symbol: {$bot->symbol}");
                
                // Проверка формата символа
                if (!preg_match('/^[A-Z0-9]+USDT$/', $bot->symbol)) {
                    $botIssues[] = '⚠️  Symbol format may be incorrect (expected: BTCUSDT, ETHUSDT, etc.)';
                }
            }

            if (empty($bot->timeframe)) {
                $botIssues[] = '❌ timeframe is empty';
            } else {
                $this->line("  Timeframe: {$bot->timeframe}");
                
                $allowedTimeframes = ["1", "3", "5", "15", "30", "60", "120", "240", "360", "720", "D", "M", "W"];
                if (!in_array($bot->timeframe, $allowedTimeframes)) {
                    $botIssues[] = '⚠️  Timeframe may be incorrect (allowed: ' . implode(', ', $allowedTimeframes) . ')';
                }
            }

            if (empty($bot->strategy)) {
                $botIssues[] = '❌ strategy is empty';
            } else {
                $this->line("  Strategy: {$bot->strategy}");
                
                $allowedStrategies = ["rsi_ema"];
                if (!in_array($bot->strategy, $allowedStrategies)) {
                    $botIssues[] = '⚠️  Strategy may be incorrect (allowed: ' . implode(', ', $allowedStrategies) . ')';
                }
            }

            if ($bot->position_size === null || $bot->position_size <= 0) {
                $botIssues[] = '❌ position_size is invalid (must be > 0)';
            } else {
                $this->line("  Position Size: {$bot->position_size} USDT");
                
                // Проверка минимальной суммы
                $minNotional = config('trading.min_notional_usdt', 1);
                if ($bot->position_size < $minNotional) {
                    $botIssues[] = "⚠️  Position size ({$bot->position_size}) is less than minimum ({$minNotional} USDT)";
                }
            }

            $this->line("  Is Active: " . ($bot->is_active ? '✅ Yes' : '❌ No'));
            $this->line("  Dry Run: " . ($bot->dry_run ? '✅ Yes (test mode)' : '❌ No (real trading)'));
            
            if ($bot->last_trade_at) {
                $this->line("  Last Trade: {$bot->last_trade_at}");
            } else {
                $this->line("  Last Trade: Never");
            }

            // Проверка связей
            if (!$bot->user) {
                $botIssues[] = '❌ User not found (may be deleted)';
            }

            if ($botIssues) {
                $this->line('');
                $this->warn('Issues found:');
                foreach ($botIssues as $issue) {
                    $this->line("  {$issue}");
                }
                $issues[$bot->id] = $botIssues;
            } else {
                $this->line('');
                $this->info('✅ Bot is valid');
                $valid++;
            }
        }

        $this->line('');
        $this->line(str_repeat('=', 60));
        $this->info("Summary:");
        $this->line("  Total bots: {$bots->count()}");
        $this->line("  Valid: {$valid}");
        $this->line("  With issues: " . count($issues));

        if (!empty($issues)) {
            $this->line('');
            $this->warn('Bots with issues:');
            foreach ($issues as $botId => $botIssues) {
                $this->line("  Bot #{$botId}: " . count($botIssues) . " issue(s)");
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
