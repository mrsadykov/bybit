<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use Carbon\Carbon;

class RecoverTradesCommand extends Command
{
    protected $signature = 'trades:recover
                            {--symbol= : Symbol to recover (e.g. BTCUSDT)}
                            {--all : Get all orders without symbol filter}';

    protected $description = 'Recover missing trades from exchange and sync them into local DB';

    public function handle(): int
    {
        $this->info('Starting trades recovery...');
        $this->line('');

        $symbolFilter = $this->option('symbol');
        $getAllOrders = $this->option('all');

        $bots = TradingBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get();

        if ($bots->isEmpty()) {
            $this->warn('No active bots found');
            return self::SUCCESS;
        }

        // Если --all, используем первый бот только для получения аккаунта
        if ($getAllOrders) {
            $bot = $bots->first();
            if (!$bot || !$bot->exchangeAccount) {
                $this->error('No active bot with exchange account found');
                return self::FAILURE;
            }
            
            $this->line(str_repeat('-', 40));
            $this->info("Getting ALL orders (without symbol filter)...");
            $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
            $exchange = $bot->exchangeAccount->exchange;
            
            try {
                $this->line("Fetching all orders from " . strtoupper($exchange) . "...");
                
                if (!method_exists($exchangeService, 'getOrdersHistory')) {
                    $this->error("Exchange {$exchange} does not support getOrdersHistory()");
                    return self::FAILURE;
                }
                
                $response = $exchangeService->getOrdersHistory(null, 500);
                
                // Обрабатываем разные форматы ответов
                $allOrders = [];
                if ($exchange === 'bybit') {
                    $allOrders = $response['result']['list'] ?? [];
                } elseif ($exchange === 'okx') {
                    $allOrders = $response['data'] ?? [];
                }
                
                if (empty($allOrders)) {
                    $this->warn('No orders found');
                    $this->line('Response: ' . json_encode($response, JSON_PRETTY_PRINT));
                    return self::SUCCESS;
                }
                
                $this->info("Found " . count($allOrders) . " total orders");
                
                // Группируем ордера по символам и обрабатываем через соответствующие боты
                $ordersBySymbol = [];
                foreach ($allOrders as $order) {
                    $symbol = $this->extractSymbol($order, $exchange);
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
                    $this->processOrders($bot, $ordersBySymbol[$bot->symbol], $exchange);
                }
                
                $this->info('Recovery finished.');
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('Exchange error: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        foreach ($bots as $bot) {

            if (! $bot->exchangeAccount) {
                $this->warn("Bot #{$bot->id}: no exchange account");
                continue;
            }

            if ($symbolFilter && $bot->symbol !== $symbolFilter) {
                continue;
            }

            $this->line(str_repeat('-', 40));
            $this->info("Bot #{$bot->id} | {$bot->symbol}");
            
            $exchangeService = ExchangeServiceFactory::create($bot->exchangeAccount);
            $exchange = $bot->exchangeAccount->exchange;

            try {
                $this->line("Fetching orders from " . strtoupper($exchange) . "...");
                
                if (!method_exists($exchangeService, 'getOrdersHistory')) {
                    $this->warn("Exchange {$exchange} does not support getOrdersHistory()");
                    continue;
                }
                
                // Пробуем получить все ордера без фильтра по символу, затем фильтруем
                $response = $exchangeService->getOrdersHistory(
                    null, // Без фильтра по символу
                    200
                );
                
                // Обрабатываем разные форматы ответов
                $orders = [];
                if ($exchange === 'bybit') {
                    $orders = $response['result']['list'] ?? [];
                } elseif ($exchange === 'okx') {
                    $orders = $response['data'] ?? [];
                }
                
                // Если получили ордера, фильтруем по символу
                if (!empty($orders)) {
                    $this->info("Found " . count($orders) . " total orders from " . strtoupper($exchange));
                    $orders = array_filter($orders, function($order) use ($bot, $exchange) {
                        $symbol = $this->extractSymbol($order, $exchange);
                        return $symbol === $bot->symbol;
                    });
                    $this->info("Filtered to " . count($orders) . " orders for {$bot->symbol}");
                } else {
                    // Если без фильтра ничего не получили, пробуем с фильтром
                    $this->line("No orders without filter, trying with symbol filter...");
                    $response = $exchangeService->getOrdersHistory(
                        $bot->symbol,
                        200
                    );
                    
                    if ($exchange === 'bybit') {
                        $orders = $response['result']['list'] ?? [];
                    } elseif ($exchange === 'okx') {
                        $orders = $response['data'] ?? [];
                    }
                }
            } catch (\Throwable $e) {
                $this->error('Exchange error: ' . $e->getMessage());
                continue;
            }

            if (empty($orders)) {
                $this->warn('No orders returned from exchange');
                $this->line('');
                $this->line('Possible reasons:');
                $this->line('1. No orders exist for this account/symbol');
                $this->line('2. Orders are older than API retention period');
                $this->line('3. Check on exchange website if orders exist');
                $this->line('');
                continue;
            }
            
            $this->processOrders($bot, $orders, $exchange);
        }

        $this->info('Recovery finished.');
        return self::SUCCESS;
    }

    protected function extractSymbol(array $order, string $exchange): ?string
    {
        if ($exchange === 'bybit') {
            return $order['symbol'] ?? null;
        } elseif ($exchange === 'okx') {
            // OKX использует instId в формате BTC-USDT
            $instId = $order['instId'] ?? '';
            return str_replace('-', '', $instId); // Конвертируем в BTCUSDT
        }
        
        return null;
    }

    protected function processOrders($bot, array $orders, string $exchange): void
    {
        if (empty($orders)) {
            return;
        }

        $this->info("Processing " . count($orders) . " orders for {$bot->symbol}");

        $created = 0;
        $updated = 0;

        // Сначала создаем/обновляем все ордера
        foreach ($orders as $order) {

            $orderId = $this->extractOrderId($order, $exchange);

            if (! $orderId) {
                continue;
            }

            // Обрабатываем статусы для разных бирж
            $status = $this->extractStatus($order, $exchange);
            
            $filledAt = $this->extractFilledAt($order, $exchange);

            $tradeData = [
                'symbol'       => $this->extractSymbol($order, $exchange) ?? $bot->symbol,
                'side'         => $this->extractSide($order, $exchange),
                'price'        => $this->extractPrice($order, $exchange),
                'quantity'     => $this->extractQuantity($order, $exchange),
                'fee'          => $this->extractFee($order, $exchange),
                'fee_currency' => $this->extractFeeCurrency($order, $exchange),
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

        // После создания всех ордеров, восстанавливаем связи между BUY и SELL
        $this->restoreTradeRelationships($bot);

        $this->info("Recovered: {$created} created, {$updated} updated");
    }

    /**
     * Восстанавливает связи между BUY и SELL ордерами
     * и закрывает позиции с расчетом PnL
     */
    protected function restoreTradeRelationships($bot): void
    {
        $this->line("Restoring trade relationships...");

        // Получаем все BUY ордера, отсортированные по времени создания
        $buyTrades = $bot->trades()
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->orderBy('filled_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Получаем все SELL ордера без parent_id, отсортированные по времени создания
        $sellTrades = $bot->trades()
            ->where('side', 'SELL')
            ->where('status', 'FILLED')
            ->whereNull('parent_id')
            ->orderBy('filled_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($buyTrades->isEmpty() || $sellTrades->isEmpty()) {
            $this->line("  No trades to link");
            return;
        }

        $linked = 0;
        $closed = 0;

        // Связываем SELL ордера с BUY ордерами по принципу FIFO
        foreach ($sellTrades as $sell) {
            // Находим первый открытый BUY, который еще не закрыт
            $buy = $buyTrades->first(function ($buy) {
                return $buy->closed_at === null;
            });

            if (!$buy) {
                break; // Нет открытых BUY для связывания
            }

            // Связываем SELL с BUY
            $sell->update(['parent_id' => $buy->id]);
            $linked++;

            // Проверяем, закрыт ли BUY полностью
            $totalSold = $bot->trades()
                ->where('side', 'SELL')
                ->where('parent_id', $buy->id)
                ->where('status', 'FILLED')
                ->sum('quantity');

            // Если продано больше или равно купленному, закрываем позицию
            if ($totalSold >= $buy->quantity) {
                // Рассчитываем PnL для всех SELL, связанных с этим BUY
                $sellTradesForBuy = $bot->trades()
                    ->where('side', 'SELL')
                    ->where('parent_id', $buy->id)
                    ->where('status', 'FILLED')
                    ->get();

                $totalSellValue = $sellTradesForBuy->sum(function ($sell) {
                    return $sell->price * $sell->quantity;
                });

                $totalSellFees = $sellTradesForBuy->sum('fee') ?? 0;

                $pnl = (
                    $totalSellValue
                    - ($buy->price * $buy->quantity)
                    - ($buy->fee ?? 0)
                    - $totalSellFees
                );

                $buy->update([
                    'closed_at' => $buy->filled_at ?? now(),
                    'realized_pnl' => $pnl,
                ]);

                $closed++;
            }
        }

        if ($linked > 0 || $closed > 0) {
            $this->info("  Linked: {$linked} SELL orders, Closed: {$closed} positions");
        }
    }

    protected function extractOrderId(array $order, string $exchange): ?string
    {
        if ($exchange === 'bybit') {
            return $order['orderId'] ?? null;
        } elseif ($exchange === 'okx') {
            return $order['ordId'] ?? null;
        }
        
        return null;
    }

    protected function extractStatus(array $order, string $exchange): string
    {
        if ($exchange === 'bybit') {
            return match ($order['orderStatus'] ?? '') {
                'Filled'           => 'FILLED',
                'PartiallyFilled'  => 'PARTIALLY_FILLED',
                'Cancelled'        => 'CANCELLED',
                'Rejected'         => 'FAILED',
                'Deactivated'      => 'FAILED',
                default            => 'SENT',
            };
        } elseif ($exchange === 'okx') {
            return match ($order['state'] ?? '') {
                'filled'           => 'FILLED',
                'partially_filled' => 'PARTIALLY_FILLED',
                'canceled'         => 'CANCELLED',
                'cancelled'        => 'CANCELLED',
                'rejected'         => 'FAILED',
                'failed'           => 'FAILED',
                default            => 'SENT',
            };
        }
        
        return 'SENT';
    }

    protected function extractSide(array $order, string $exchange): string
    {
        if ($exchange === 'bybit') {
            return strtoupper($order['side'] ?? 'BUY');
        } elseif ($exchange === 'okx') {
            return strtoupper($order['side'] ?? 'BUY');
        }
        
        return 'BUY';
    }

    protected function extractPrice(array $order, string $exchange): float
    {
        if ($exchange === 'bybit') {
            return (float) ($order['avgPrice'] ?? $order['price'] ?? 0);
        } elseif ($exchange === 'okx') {
            return (float) ($order['avgPx'] ?? $order['px'] ?? 0);
        }
        
        return 0.0;
    }

    protected function extractQuantity(array $order, string $exchange): float
    {
        if ($exchange === 'bybit') {
            return (float) ($order['cumExecQty'] ?? $order['qty'] ?? 0);
        } elseif ($exchange === 'okx') {
            return (float) ($order['accFillSz'] ?? $order['sz'] ?? 0);
        }
        
        return 0.0;
    }

    protected function extractFee(array $order, string $exchange): float
    {
        if ($exchange === 'bybit') {
            return (float) ($order['cumExecFee'] ?? 0);
        } elseif ($exchange === 'okx') {
            return (float) ($order['fee'] ?? 0);
        }
        
        return 0.0;
    }

    protected function extractFeeCurrency(array $order, string $exchange): ?string
    {
        if ($exchange === 'bybit') {
            return $order['feeCurrency'] ?? null;
        } elseif ($exchange === 'okx') {
            return $order['feeCcy'] ?? null;
        }
        
        return null;
    }

    protected function extractFilledAt(array $order, string $exchange): ?Carbon
    {
        if ($exchange === 'bybit') {
            if (! empty($order['updatedTime'])) {
                return Carbon::createFromTimestampMs((int) $order['updatedTime']);
            }
        } elseif ($exchange === 'okx') {
            if (! empty($order['uTime'])) {
                // OKX использует timestamp в миллисекундах как строка
                return Carbon::createFromTimestampMs((int) $order['uTime']);
            } elseif (! empty($order['cTime'])) {
                return Carbon::createFromTimestampMs((int) $order['cTime']);
            }
        }
        
        return null;
    }
}
