<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\Bybit\BybitService;
use Carbon\Carbon;

class RecoverBybitTradesCommand extends Command
{
    protected $signature = 'trades:recover-bybit
                            {--symbol= : Symbol to recover (e.g. BTCUSDT)}
                            {--all : Get all orders without symbol filter}';

    protected $description = 'Recover missing trades from Bybit and sync them into local DB';

    public function handle(): int
    {
        $this->info('Starting Bybit trades recovery...');

        $symbolFilter = $this->option('symbol');
        $getAllOrders = $this->option('all');

        // Фильтруем только боты с Bybit аккаунтами
        $bots = TradingBot::with('exchangeAccount')
            ->where('is_active', true)
            ->whereHas('exchangeAccount', function ($query) {
                $query->where('exchange', 'bybit');
            })
            ->get();

        if ($bots->isEmpty()) {
            $this->warn('No active bots found');
            return self::SUCCESS;
        }

        // Если --all, используем первый бот только для получения аккаунта
        if ($getAllOrders) {
            $bot = $bots->first();
            if (!$bot || !$bot->exchangeAccount) {
                $this->error('No active Bybit bot with exchange account found');
                return self::FAILURE;
            }
            
            // Проверяем, что это действительно Bybit аккаунт
            if ($bot->exchangeAccount->exchange !== 'bybit') {
                $this->error("Bot #{$bot->id} uses {$bot->exchangeAccount->exchange} exchange, not Bybit. Use appropriate recovery command.");
                return self::FAILURE;
            }
            
            $this->line(str_repeat('-', 40));
            $this->info("Getting ALL orders (without symbol filter)...");
            $bybit = new BybitService($bot->exchangeAccount);
            
            try {
                $this->line("Fetching all orders...");
                $response = $bybit->getOrdersHistory(null, 500);
                $allOrders = $response['result']['list'] ?? [];
                
                if (empty($allOrders)) {
                    $this->warn('No orders found');
                    $this->line('Response: ' . json_encode($response, JSON_PRETTY_PRINT));
                    return self::SUCCESS;
                }
                
                $this->info("Found " . count($allOrders) . " total orders");
                
                // Группируем ордера по символам и обрабатываем через соответствующие боты
                $ordersBySymbol = [];
                foreach ($allOrders as $order) {
                    $symbol = $order['symbol'] ?? '';
                    if ($symbol) {
                        $ordersBySymbol[$symbol][] = $order;
                    }
                }
                
                foreach ($bots as $bot) {
                    if (!isset($ordersBySymbol[$bot->symbol])) {
                        continue;
                    }
                    
                    $this->line(str_repeat('-', 40));
                    $this->info("Processing {$bot->symbol} orders...");
                    $this->processOrders($bot, $ordersBySymbol[$bot->symbol]);
                }
                
                $this->info('Recovery finished.');
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('Bybit error: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        foreach ($bots as $bot) {

            if (! $bot->exchangeAccount) {
                $this->warn("Bot #{$bot->id}: no exchange account");
                continue;
            }

            // Проверяем, что это Bybit аккаунт
            if ($bot->exchangeAccount->exchange !== 'bybit') {
                $this->warn("Bot #{$bot->id}: uses {$bot->exchangeAccount->exchange} exchange, skipping (use appropriate recovery command)");
                continue;
            }

            if ($symbolFilter && $bot->symbol !== $symbolFilter) {
                continue;
            }

            $this->line(str_repeat('-', 40));
            $this->info("Bot #{$bot->id} | {$bot->symbol}");

            $bybit = new BybitService($bot->exchangeAccount);

            try {
                // Пробуем получить все ордера без фильтра по символу, затем фильтруем
                $this->line("Fetching all orders (without symbol filter)...");
                
                // Сначала пробуем получить все ордера без фильтра по символу
                $response = $bybit->getOrdersHistory(
                    null, // Без фильтра по символу
                    200
                );
                
                $orders = $response['result']['list'] ?? [];
                
                // Если получили ордера, фильтруем по символу
                if (!empty($orders)) {
                    $this->info("Found " . count($orders) . " total orders from Bybit");
                    $orders = array_filter($orders, function($order) use ($bot) {
                        return ($order['symbol'] ?? '') === $bot->symbol;
                    });
                    $this->info("Filtered to " . count($orders) . " orders for {$bot->symbol}");
                } else {
                    // Если без фильтра ничего не получили, пробуем с фильтром
                    $this->line("No orders without filter, trying with symbol filter...");
                    $response = $bybit->getOrdersHistory(
                        $bot->symbol,
                        200
                    );
                    $orders = $response['result']['list'] ?? [];
                    
                    // Если и это не помогло, пробуем executions
                    if (empty($orders)) {
                        $this->line("No orders found, trying executions...");
                        $execResponse = $bybit->getExecutions(
                            $bot->symbol,
                            200
                        );
                        $orders = $execResponse['result']['list'] ?? [];
                    }
                }
            } catch (\Throwable $e) {
                $this->error('Bybit error: ' . $e->getMessage());
                $this->line('Full error: ' . $e->getTraceAsString());
                continue;
            }

            if (empty($orders)) {
                $this->warn('No orders/executions returned from Bybit');
                $this->line('');
                $this->line('Possible reasons:');
                $this->line('1. No orders exist for this account/symbol');
                $this->line('2. Orders are older than API retention period (check Bybit docs)');
                $this->line('3. Check on bybit.com if orders exist');
                $this->line('4. Try checking all orders without symbol filter on bybit.com');
                $this->line('');
                $this->line('Last response: ' . json_encode($response, JSON_PRETTY_PRINT));
                continue;
            }
            
            $this->processOrders($bot, $orders);
        }

        $this->info('Recovery finished.');
        return self::SUCCESS;
    }

    protected function processOrders($bot, array $orders): void
    {
        if (empty($orders)) {
            return;
        }

        $this->info("Processing " . count($orders) . " orders for {$bot->symbol}");

        $created = 0;
        $updated = 0;

        foreach ($orders as $order) {

            $orderId = $order['orderId'] ?? null;

            if (! $orderId) {
                continue;
            }

            // Обрабатываем все статусы
            $status = match ($order['orderStatus'] ?? '') {
                'Filled'           => 'FILLED',
                'PartiallyFilled'  => 'PARTIALLY_FILLED',
                'Cancelled'        => 'CANCELLED',
                'Rejected'         => 'FAILED',
                'Deactivated'      => 'FAILED',
                default            => 'SENT',
            };

            $filledAt = null;

            if (! empty($order['updatedTime'])) {
                $filledAt = Carbon::createFromTimestampMs(
                    (int) $order['updatedTime']
                );
            }

            $tradeData = [
                'symbol'       => $order['symbol'] ?? $bot->symbol,
                'side'         => strtoupper($order['side'] ?? 'BUY'),
                'price'        => (float) ($order['avgPrice'] ?? $order['price'] ?? 0),
                'quantity'     => (float) ($order['cumExecQty'] ?? $order['qty'] ?? 0),
                'fee'          => (float) ($order['cumExecFee'] ?? 0),
                'fee_currency' => $order['feeCurrency'] ?? null,
                'status'       => $status,
                'filled_at'    => $filledAt,
            ];

            $existingTrade = $bot->trades()->where('order_id', $orderId)->first();
            
            if ($existingTrade) {
                $existingTrade->update($tradeData);
                $updated++;
            } else {
                $bot->trades()->create(array_merge($tradeData, [
                    'order_id' => $orderId,
                ]));
                $created++;
            }
        }

        $this->info("Recovered: {$created} created, {$updated} updated");
    }
}
