<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class SyncOrdersCommand extends Command
{
    protected $signature = 'orders:sync';
    protected $description = 'Sync pending exchange orders';

    public function handle(): int
    {
        $this->info('Starting sync trades ...');
        $this->line('');

        // Синхронизируем все трейды с order_id, не только PENDING/SENT
        // Это позволит обновить статусы уже заполненных ордеров
        $trades = Trade::whereNotNull('order_id')
            ->whereIn('status', ['PENDING', 'SENT', 'PARTIALLY_FILLED', 'FILLED'])
            ->with('bot.exchangeAccount')
            ->get();

        if ($trades->isEmpty()) {
            $this->info('No trades to sync.');
            return self::SUCCESS;
        }

        $this->info("Found {$trades->count()} trade(s) to sync:");
        $this->line('');

        $synced = 0;
        $notFound = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($trades as $trade) {
            $this->line("Trade #{$trade->id} ({$trade->side}) - Order ID: {$trade->order_id}");
            $this->line("  Status: {$trade->status} | Symbol: {$trade->symbol}");
            
            // Проверяем наличие bot и exchangeAccount
            if (!$trade->bot) {
                $this->warn("  ⚠️  Skipped: No bot attached");
                $skipped++;
                $this->line('');
                continue;
            }
            
            if (!$trade->bot->exchangeAccount) {
                $this->warn("  ⚠️  Skipped: No exchange account attached");
                $skipped++;
                $this->line('');
                continue;
            }
            
            try {
                $exchangeService = ExchangeServiceFactory::create($trade->bot->exchangeAccount);
                $exchange = $trade->bot->exchangeAccount->exchange;
                
                $this->line("  Exchange: " . strtoupper($exchange));

                // Сначала пытаемся получить текущий ордер
                $response = $exchangeService->getOrder(
                    $trade->symbol,
                    $trade->order_id
                );

                // Обрабатываем разные форматы ответов
                $order = null;
                if ($exchange === 'bybit') {
                    $order = $response['result']['list'][0] ?? null;
                    
                    // Если не нашли — идём в history (только для Bybit)
                    if (! $order && method_exists($exchangeService, 'getOrderHistory')) {
                        $this->line("  Order not found in active orders, checking history...");
                        $historyResponse = $exchangeService->getOrderHistory(
                            $trade->symbol,
                            $trade->order_id
                        );
                        $order = $historyResponse['result']['list'][0] ?? null;
                    }
                } elseif ($exchange === 'okx') {
                    $order = $response['data'][0] ?? null;
                    
                    // Для OKX заполненные ордера могут быть только в истории
                    if (! $order && method_exists($exchangeService, 'getOrderHistory')) {
                        $this->line("  Order not found in active orders, checking history...");
                        $historyResponse = $exchangeService->getOrderHistory(
                            $trade->symbol,
                            $trade->order_id
                        );
                        $order = $historyResponse['data'][0] ?? null;
                    }
                }

                if (! $order) {
                    $this->warn("  ❌ Order not found on exchange");
                    $notFound++;
                    logger()->warning('Order not found in sync', [
                        'trade_id' => $trade->id,
                        'order_id' => $trade->order_id,
                        'exchange' => $exchange,
                        'response_keys' => array_keys($response),
                    ]);
                    $this->line('');
                    continue;
                }

                // Обрабатываем статус ордера (разные форматы для разных бирж)
                $isFilled = false;
                $isPartiallyFilled = false;
                $executedQty = 0;
                $executedPrice = 0;
                $fee = 0;
                $feeCurrency = null;
                
                if ($exchange === 'bybit') {
                    $isFilled = ($order['orderStatus'] ?? '') === 'Filled';
                    $isPartiallyFilled = ($order['orderStatus'] ?? '') === 'PartiallyFilled';
                    $executedQty = (float) ($order['cumExecQty'] ?? 0);
                    $executedPrice = (float) ($order['avgPrice'] ?? $trade->price);
                    $fee = (float) ($order['cumExecFee'] ?? 0);
                    $feeCurrency = $order['feeCurrency'] ?? null;
                } elseif ($exchange === 'okx') {
                    $isFilled = ($order['state'] ?? '') === 'filled';
                    $isPartiallyFilled = ($order['state'] ?? '') === 'partially_filled';
                    $executedQty = (float) ($order['accFillSz'] ?? 0);
                    $executedPrice = (float) ($order['avgPx'] ?? $order['px'] ?? $trade->price);
                    $fee = (float) ($order['fee'] ?? 0);
                    $feeCurrency = $order['feeCcy'] ?? null;
                }

                $orderStatus = $exchange === 'bybit' 
                    ? ($order['orderStatus'] ?? 'Unknown')
                    : ($order['state'] ?? 'Unknown');
                
                $this->line("  Order status on exchange: {$orderStatus}");

                // Обработка Filled и PartiallyFilled ордеров
                if ($isFilled || $isPartiallyFilled) {
                    
                    // Проверяем, нужно ли обновление
                    $needsUpdate = false;
                    if ($trade->status !== ($isFilled ? 'FILLED' : 'PARTIALLY_FILLED')) {
                        $needsUpdate = true;
                    }
                    if (abs($trade->quantity - $executedQty) > 0.00000001) {
                        $needsUpdate = true;
                    }
                    if (abs($trade->price - $executedPrice) > 0.01) {
                        $needsUpdate = true;
                    }
                    
                    if ($needsUpdate || !$trade->filled_at) {
                        // 1. обновляем текущий трейд
                        $trade->update([
                            'price'        => $executedPrice,
                            'quantity'     => $executedQty,
                            'fee'          => $fee,
                            'fee_currency' => $feeCurrency,
                            'status'       => $isFilled ? 'FILLED' : 'PARTIALLY_FILLED',
                            'filled_at'    => $isFilled ? ($trade->filled_at ?? now()) : null,
                        ]);

                        $this->info("  ✅ Order {$orderStatus} - Updated!");
                        $this->line("     Quantity: {$executedQty} | Price: {$executedPrice} | Fee: {$fee} {$feeCurrency}");
                        $synced++;

                        // Уведомление в Telegram о заполнении ордера
                        if ($isFilled && $needsUpdate) {
                            $telegram = new TelegramService();
                            $telegram->notifyFilled($trade->side, $trade->symbol, $executedQty, $executedPrice, $fee);
                        }
                    } else {
                        $this->line("  ✓ Order already synced (no changes needed)");
                    }

                    logger()->info('Order execution update', [
                        'trade_id' => $trade->id,
                        'order_id' => $trade->order_id,
                        'status' => $orderStatus,
                        'executed_qty' => $executedQty,
                        'price' => $executedPrice,
                    ]);

                    // 2. если это SELL и полностью исполнен — закрываем все связанные BUY позиции (FIFO)
                    if ($isFilled && $trade->side === 'SELL' && $trade->bot) {
                        // Если нет parent_id, связываем с первым открытым BUY
                        if (!$trade->parent_id) {
                            $firstBuy = Trade::where('trading_bot_id', $trade->bot->id)
                                ->where('side', 'BUY')
                                ->where('status', 'FILLED')
                                ->whereNull('closed_at')
                                ->orderBy('filled_at', 'asc')
                                ->orderBy('id', 'asc')
                                ->first();

                            if ($firstBuy) {
                                $trade->update(['parent_id' => $firstBuy->id]);
                                $this->info("  🔗 SELL linked to BUY #{$firstBuy->id}");
                            }
                        }

                        // Закрываем все BUY позиции, которые были проданы этим SELL (FIFO)
                        $remainingSellQty = $trade->quantity;
                        $closedPositions = 0;
                        $totalPnL = 0;

                        // Получаем все открытые BUY позиции (FIFO)
                        $openBuys = Trade::where('trading_bot_id', $trade->bot->id)
                            ->where('side', 'BUY')
                            ->where('status', 'FILLED')
                            ->whereNull('closed_at')
                            ->orderBy('filled_at', 'asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        foreach ($openBuys as $buy) {
                            if ($remainingSellQty <= 0) {
                                break; // Весь SELL уже распределен
                            }

                            // Определяем, сколько из этого BUY было продано
                            $buyQtySold = min($remainingSellQty, $buy->quantity);
                            $remainingSellQty -= $buyQtySold;

                            // Рассчитываем PnL для этой части
                            // Пропорционально распределяем цену продажи и комиссию
                            $sellPriceRatio = $buyQtySold / $trade->quantity;
                            $sellValueForBuy = $trade->price * $buyQtySold;
                            $sellFeeForBuy = ($trade->fee ?? 0) * $sellPriceRatio;

                            $pnl = (
                                $sellValueForBuy
                                - ($buy->price * $buyQtySold)
                                - (($buy->fee ?? 0) * ($buyQtySold / $buy->quantity))
                                - $sellFeeForBuy
                            );

                            // Если продано все количество BUY, закрываем позицию
                            if ($buyQtySold >= $buy->quantity) {
                                $buy->update([
                                    'closed_at'    => $trade->filled_at ?? now(),
                                    'realized_pnl' => $pnl,
                                ]);

                                $closedPositions++;
                                $totalPnL += $pnl;

                                $this->info("  💰 Position #{$buy->id} closed! PnL: " . number_format($pnl, 8) . " USDT");

                                logger()->info('Position closed', [
                                    'buy_trade_id' => $buy->id,
                                    'sell_trade_id' => $trade->id,
                                    'pnl' => $pnl,
                                    'buy_price' => $buy->price,
                                    'sell_price' => $trade->price,
                                    'quantity_sold' => $buyQtySold,
                                ]);
                            }
                        }

                        // Отправляем уведомление в Telegram, если закрыты позиции
                        if ($closedPositions > 0) {
                            $telegram = new TelegramService();
                            $pnlEmoji = $totalPnL >= 0 ? '📈' : '📉';
                            $telegram->sendMessage(
                                "{$pnlEmoji} <b>POSITION(S) CLOSED</b>\n\n" .
                                "Symbol: <b>{$trade->symbol}</b>\n" .
                                "Sell Quantity: <b>{$trade->quantity}</b>\n" .
                                "Sell Price: <b>\${$trade->price}</b>\n" .
                                "Closed Positions: <b>{$closedPositions}</b>\n" .
                                "Total PnL: <b>" . number_format($totalPnL, 8) . " USDT</b>\n" .
                                "Time: " . now()->format('Y-m-d H:i:s')
                            );
                        }
                    }

                    $this->line('');
                    continue;
                }

                // Обработка отмененных/отклоненных ордеров
                $isCancelled = false;
                $isRejected = false;
                
                if ($exchange === 'bybit') {
                    $isCancelled = in_array($order['orderStatus'] ?? '', ['Cancelled', 'Rejected'], true);
                    $isRejected = ($order['orderStatus'] ?? '') === 'Rejected';
                } elseif ($exchange === 'okx') {
                    $isCancelled = in_array($order['state'] ?? '', ['canceled', 'cancelled'], true);
                    $isRejected = in_array($order['state'] ?? '', ['rejected', 'failed'], true);
                }

                if ($isCancelled || $isRejected) {
                    $trade->update([
                        'status' => 'FAILED',
                    ]);
                    $this->warn("  ⚠️  Order {$orderStatus} - Marked as FAILED");
                    $synced++;
                } else {
                    $this->line("  ℹ️  Order still {$orderStatus} - No update needed");
                }

                $this->line('');

            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ❌ Error: " . $e->getMessage());
                $this->line('');
                logger()->error('Order sync error', [
                    'trade_id' => $trade->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info('Sync summary:');
        $this->line("  ✅ Synced: {$synced}");
        $this->line("  ❌ Not found: {$notFound}");
        $this->line("  ⚠️  Errors: {$errors}");
        $this->line("  ⏭️  Skipped: {$skipped}");
        $this->line('');
        $this->info('Trades sync processed.');
        
        return self::SUCCESS;
    }
}
