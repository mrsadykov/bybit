<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;

class SyncPositionFromBalanceCommand extends Command
{
    protected $signature = 'position:sync-from-balance
                            {--bot= : Bot ID to sync}
                            {--force : Override existing position}';
    
    protected $description = 'Sync trading bot position from exchange balance (useful when order history is unavailable)';

    public function handle(): int
    {
        $this->info('Syncing position from exchange balance...');
        $this->line('');

        $botId = $this->option('bot');
        $force = $this->option('force');

        $query = TradingBot::with('exchangeAccount')
            ->where('is_active', true);

        if ($botId) {
            $query->where('id', $botId);
        }

        $bots = $query->get();

        if ($bots->isEmpty()) {
            $this->warn('No active bots found');
            return self::FAILURE;
        }

        foreach ($bots as $bot) {
            if (!$bot->exchangeAccount) {
                $this->warn("Bot #{$bot->id}: no exchange account");
                continue;
            }

            $this->line(str_repeat('-', 40));
            $this->info("Bot #{$bot->id} | {$bot->symbol}");

            try {
                $bybit = new BybitService($bot->exchangeAccount);
                
                // Получаем базовую монету из символа (например BTC из BTCUSDT)
                $baseCoin = str_replace('USDT', '', $bot->symbol);
                
                $this->line("Fetching {$baseCoin} balance from exchange...");
                $balance = $bybit->getBalance($baseCoin);
                
                $this->info("Exchange balance: {$balance} {$baseCoin}");
                
                // Получаем текущую позицию из БД
                $positionManager = new \App\Services\Trading\PositionManager($bot);
                $dbPosition = $positionManager->getNetPosition();
                
                $this->line("Database position: {$dbPosition} {$baseCoin}");
                $this->line("Difference: " . ($balance - $dbPosition) . " {$baseCoin}");
                
                if (abs($balance - $dbPosition) < 0.00000001) {
                    $this->info("✅ Position is already in sync!");
                    continue;
                }
                
                if (!$force && $balance > 0 && $dbPosition > 0) {
                    $this->warn("⚠️  Position mismatch detected!");
                    $this->line("Use --force to create a synthetic BUY trade to sync positions");
                    $this->line("Or manually add trades to match the balance");
                    continue;
                }
                
                if ($force) {
                    $this->warn("⚠️  Creating synthetic BUY trade to sync position...");
                    
                    // Получаем текущую цену
                    $currentPrice = $bybit->getPrice($bot->symbol);
                    $this->line("Current price: {$currentPrice} USDT");
                    
                    // Создаем синтетическую сделку BUY
                    $diff = $balance - $dbPosition;
                    
                    if ($diff > 0) {
                        // Нужно добавить BUY сделку
                        $bot->trades()->create([
                            'symbol' => $bot->symbol,
                            'side' => 'BUY',
                            'price' => $currentPrice,
                            'quantity' => $diff,
                            'status' => 'FILLED',
                            'filled_at' => now(),
                            'order_id' => 'SYNC-' . time() . '-' . $bot->id,
                        ]);
                        
                        $this->info("✅ Created synthetic BUY trade: {$diff} {$baseCoin} @ {$currentPrice} USDT");
                    } elseif ($diff < 0) {
                        // Нужно добавить SELL сделку (но это реже)
                        $this->warn("⚠️  Balance is less than DB position. This is unusual.");
                        $this->line("You may need to manually adjust trades.");
                    }
                } else {
                    $this->info("💡 To sync positions:");
                    $this->line("1. Export order history from Bybit website (if available)");
                    $this->line("2. Manually import old trades");
                    $this->line("3. Or use --force to create a synthetic trade (less accurate)");
                }
                
            } catch (\Throwable $e) {
                $this->error("Error: " . $e->getMessage());
                continue;
            }
        }

        $this->line('');
        $this->info('Sync completed!');
        return self::SUCCESS;
    }
}
