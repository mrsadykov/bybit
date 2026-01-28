<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\Trading\PositionManager;
use Illuminate\Console\Command;

class MonitorTradingSystemCommand extends Command
{
    protected $signature = 'monitor:system 
                            {--bot= : Monitor specific bot ID}
                            {--errors : Show only errors}
                            {--recent : Show recent trades (last 10)}';
    
    protected $description = 'Мониторинг торговой системы (Trading system monitoring)';

    public function handle(): int
    {
        $this->info('🔍 Мониторинг торговой системы (Trading System Monitoring)');
        $this->line('');

        // 1. Статус ботов
        $this->showBotsStatus();

        // 2. Последние сделки
        if ($this->option('recent') || !$this->option('errors')) {
            $this->showRecentTrades();
        }

        // 3. Ошибки
        $this->showErrors();

        // 4. Балансы и позиции
        if (!$this->option('errors')) {
            $this->showBalancesAndPositions();
        }

        // 5. Проверка минимальных размеров
        if (!$this->option('errors')) {
            $this->checkMinOrderSizes();
        }

        return self::SUCCESS;
    }

    private function showBotsStatus(): void
    {
        $this->info('📊 Статус ботов (Bots Status)');
        $this->line(str_repeat('-', 80));

        $query = TradingBot::with('exchangeAccount')
            ->where('is_active', true);

        if ($botId = $this->option('bot')) {
            $query->where('id', $botId);
        }

        $bots = $query->get();

        if ($bots->isEmpty()) {
            $this->warn('  ⚠️  Активных ботов не найдено (No active bots found)');
            $this->line('');
            return;
        }

        foreach ($bots as $bot) {
            $this->line("  Bot #{$bot->id}: {$bot->symbol}");
            $this->line("    Стратегия (Strategy): {$bot->strategy}");
            $this->line("    Размер позиции (Position Size): {$bot->position_size} USDT");
            $this->line("    Таймфрейм (Timeframe): {$bot->timeframe}");
            $this->line("    Режим (Mode): " . ($bot->dry_run ? '🧪 Тестовый (Dry Run)' : '💰 Реальная торговля (Real Trading)'));
            
            // Статистика сделок
            $totalTrades = $bot->trades()->count();
            $filledTrades = $bot->trades()->where('status', 'FILLED')->count();
            $failedTrades = $bot->trades()->where('status', 'FAILED')->count();
            $openPositions = $bot->trades()
                ->where('side', 'BUY')
                ->where('status', 'FILLED')
                ->whereNull('closed_at')
                ->count();

            $this->line("    Сделок всего (Total Trades): {$totalTrades}");
            $this->line("    Успешных (Filled): {$filledTrades}");
            if ($failedTrades > 0) {
                $this->warn("    ❌ Ошибок (Failed): {$failedTrades}");
            }
            $this->line("    Открытых позиций (Open Positions): {$openPositions}");

            if ($bot->last_trade_at) {
                $lastTrade = $bot->last_trade_at->diffForHumans();
                $this->line("    Последняя сделка (Last Trade): {$lastTrade}");
            }

            $this->line('');
        }
    }

    private function showRecentTrades(): void
    {
        $this->info('📈 Последние сделки (Recent Trades)');
        $this->line(str_repeat('-', 80));

        $query = Trade::with('bot')
            ->orderBy('created_at', 'desc')
            ->limit(10);

        if ($botId = $this->option('bot')) {
            $query->where('trading_bot_id', $botId);
        }

        $trades = $query->get();

        if ($trades->isEmpty()) {
            $this->warn('  ⚠️  Сделок не найдено (No trades found)');
            $this->line('');
            return;
        }

        foreach ($trades as $trade) {
            $statusIcon = match($trade->status) {
                'FILLED' => '✅',
                'FAILED' => '❌',
                'SENT' => '⏳',
                'PENDING' => '🔄',
                default => '❓',
            };

            $this->line("  {$statusIcon} [{$trade->created_at->format('Y-m-d H:i:s')}] Bot #{$trade->trading_bot_id} - {$trade->side} {$trade->symbol}");
            
            if ($trade->status === 'FILLED') {
                $this->line("      Цена (Price): {$trade->price} | Количество (Qty): {$trade->quantity}");
                if ($trade->realized_pnl !== null) {
                    $pnlIcon = $trade->realized_pnl >= 0 ? '🟢' : '🔴';
                    $this->line("      PnL: {$pnlIcon} {$trade->realized_pnl} USDT");
                }
            } elseif ($trade->status === 'FAILED') {
                $error = $trade->exchange_response['error'] ?? 'Unknown error';
                if (str_contains($error, 'Parameter sz error')) {
                    $this->warn("      ⚠️  Ошибка минимального размера (Min order size error)");
                } else {
                    $this->error("      Ошибка (Error): " . substr($error, 0, 100));
                }
            }
        }

        $this->line('');
    }

    private function showErrors(): void
    {
        $this->info('❌ Ошибки (Errors)');
        $this->line(str_repeat('-', 80));

        $query = Trade::with('bot')
            ->where('status', 'FAILED')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc');

        if ($botId = $this->option('bot')) {
            $query->where('trading_bot_id', $botId);
        }

        $errors = $query->get();

        if ($errors->isEmpty()) {
            $this->info('  ✅ Ошибок за последние 7 дней нет (No errors in last 7 days)');
            $this->line('');
            return;
        }

        // Группируем ошибки по типу
        $errorGroups = [];
        foreach ($errors as $error) {
            $errorMsg = $error->exchange_response['error'] ?? 'Unknown error';
            
            if (str_contains($errorMsg, 'Parameter sz error')) {
                $key = 'Parameter sz error';
            } elseif (str_contains($errorMsg, 'Insufficient balance')) {
                $key = 'Insufficient balance';
            } else {
                $key = substr($errorMsg, 0, 50);
            }

            if (!isset($errorGroups[$key])) {
                $errorGroups[$key] = [];
            }
            $errorGroups[$key][] = $error;
        }

        foreach ($errorGroups as $errorType => $errorList) {
            $count = count($errorList);
            $this->warn("  {$errorType}: {$count} раз(а)");
            
            // Показываем последнюю ошибку этого типа
            $lastError = $errorList[0];
            $this->line("    Последняя (Last): [{$lastError->created_at->format('Y-m-d H:i:s')}] Bot #{$lastError->trading_bot_id} - {$lastError->side} {$lastError->symbol}");
            
            if (str_contains($errorType, 'Parameter sz error')) {
                $this->warn("    ⚠️  Количество ({$lastError->quantity}) меньше минимального размера ордера");
                $this->line("    💡 Решение: Увеличьте position_size для этого бота или накопите больше монет");
            }
            
            $this->line('');
        }
    }

    private function showBalancesAndPositions(): void
    {
        $this->info('💰 Балансы и позиции (Balances & Positions)');
        $this->line(str_repeat('-', 80));

        $query = TradingBot::with('exchangeAccount')
            ->where('is_active', true);

        if ($botId = $this->option('bot')) {
            $query->where('id', $botId);
        }

        $bots = $query->get();

        foreach ($bots as $bot) {
            if (!$bot->exchangeAccount) {
                continue;
            }

            $this->line("  Bot #{$bot->id}: {$bot->symbol}");

            try {
                $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
                $positionManager = new PositionManager($bot);

                // Баланс базовой монеты
                $baseCoin = str_replace('USDT', '', $bot->symbol);
                $balance = $exchangeService->getBalance($baseCoin);
                $netPosition = $positionManager->getNetPosition();

                $this->line("    Баланс на бирже (Exchange Balance): {$balance} {$baseCoin}");
                $this->line("    Позиция в БД (DB Position): {$netPosition} {$baseCoin}");

                // Разница
                $diff = abs($balance - $netPosition);
                if ($diff > 0.0001) {
                    $this->warn("    ⚠️  Разница (Difference): {$diff} {$baseCoin}");
                } else {
                    $this->info("    ✅ Балансы синхронизированы (Balances synced)");
                }

                // Открытые позиции
                $openBuys = $bot->trades()
                    ->where('side', 'BUY')
                    ->where('status', 'FILLED')
                    ->whereNull('closed_at')
                    ->get();

                if ($openBuys->count() > 0) {
                    $this->line("    Открытые позиции (Open Positions):");
                    foreach ($openBuys as $buy) {
                        $this->line("      - BUY #{$buy->id}: {$buy->quantity} @ {$buy->price} (создана {$buy->created_at->format('Y-m-d H:i')})");
                    }
                }

            } catch (\Throwable $e) {
                $this->error("    ❌ Ошибка получения баланса (Balance error): " . $e->getMessage());
            }

            $this->line('');
        }
    }

    private function checkMinOrderSizes(): void
    {
        $this->info('📏 Проверка минимальных размеров (Min Order Size Check)');
        $this->line(str_repeat('-', 80));

        $query = TradingBot::with('exchangeAccount')
            ->where('is_active', true);

        if ($botId = $this->option('bot')) {
            $query->where('id', $botId);
        }

        $bots = $query->get();

        $positionManager = new PositionManager($bots->first() ?? new TradingBot());

        foreach ($bots as $bot) {
            $this->line("  Bot #{$bot->id}: {$bot->symbol}");

            try {
                $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
                $baseCoin = str_replace('USDT', '', $bot->symbol);
                $balance = $exchangeService->getBalance($baseCoin);

                // Проверка минимального размера
                [$passesMin, $minQty] = $positionManager->passesMinSell($bot->symbol, $balance);

                if ($passesMin) {
                    $this->info("    ✅ Баланс ({$balance} {$baseCoin}) >= минимума ({$minQty} {$baseCoin})");
                } else {
                    $this->warn("    ⚠️  Баланс ({$balance} {$baseCoin}) < минимума ({$minQty} {$baseCoin})");
                    $this->warn("    💡 Накопите больше {$baseCoin} перед продажей");
                }

                // Проверка position_size для будущих покупок
                $currentPrice = $exchangeService->getPrice($bot->symbol);
                $expectedQty = $bot->position_size / $currentPrice;
                
                if ($expectedQty >= $minQty) {
                    $this->info("    ✅ Position size ({$bot->position_size} USDT) даст ~{$expectedQty} {$baseCoin} (>= {$minQty})");
                } else {
                    $this->warn("    ⚠️  Position size ({$bot->position_size} USDT) даст ~{$expectedQty} {$baseCoin} (< {$minQty})");
                    $minRequired = ceil($minQty * $currentPrice);
                    $this->warn("    💡 Рекомендуется увеличить position_size до минимум {$minRequired} USDT");
                }

            } catch (\Throwable $e) {
                $this->error("    ❌ Ошибка (Error): " . $e->getMessage());
            }

            $this->line('');
        }
    }
}
