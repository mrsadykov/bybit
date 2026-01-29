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
        $this->info('Начало синхронизации сделок (Starting sync trades)...');
        $this->line('');

        // Синхронизируем все трейды с order_id, не только PENDING/SENT
        // Это позволит обновить статусы уже заполненных ордеров
        $trades = Trade::whereNotNull('order_id')
            ->whereIn('status', ['PENDING', 'SENT', 'PARTIALLY_FILLED', 'FILLED'])
            ->with('bot.exchangeAccount')
            ->get();

        if ($trades->isEmpty()) {
            $this->info('Нет сделок для синхронизации (No trades to sync).');
            return self::SUCCESS;
        }

        $this->info("Найдено сделок для синхронизации (Found trades to sync): {$trades->count()}");
        $this->line('');

        $synced = 0;
        $notFound = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($trades as $trade) {
            $this->line("Сделка #{$trade->id} (Trade #{$trade->id}) ({$trade->side}) - ID ордера (Order ID): {$trade->order_id}");
            $this->line("  Статус (Status): {$trade->status} | Символ (Symbol): {$trade->symbol}");
            
            // Проверяем наличие bot и exchangeAccount
            if (!$trade->bot) {
                $this->warn("  ⚠️  Пропущено: Бот не привязан (Skipped: No bot attached)");
                $skipped++;
                $this->line('');
                continue;
            }
            
            if (!$trade->bot->exchangeAccount) {
                $this->warn("  ⚠️  Пропущено: Аккаунт биржи не привязан (Skipped: No exchange account attached)");
                $skipped++;
                $this->line('');
                continue;
            }
            
            try {
                $exchangeService = ExchangeServiceFactory::create($trade->bot->exchangeAccount);
                $exchange = $trade->bot->exchangeAccount->exchange;
                
                $this->line("  Биржа (Exchange): " . strtoupper($exchange));

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
                        $this->line("  Ордер не найден в активных, проверяем историю (Order not found in active orders, checking history)...");
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
                        $this->line("  Ордер не найден в активных, проверяем историю (Order not found in active orders, checking history)...");
                        $historyResponse = $exchangeService->getOrderHistory(
                            $trade->symbol,
                            $trade->order_id
                        );
                        $order = $historyResponse['data'][0] ?? null;
                    }
                }

                if (! $order) {
                    $this->warn("  ❌ Ордер не найден на бирже (Order not found on exchange)");
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
                
                $this->line("  Статус ордера на бирже (Order status on exchange): {$orderStatus}");

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

                    // Уведомлять в Telegram только при переходе в FILLED (ордер ещё не был FILLED в БД).
                    // Иначе bots:run уже отправил notifyFilled → sync не дублирует при обновлении цены/объёма.
                    $wasNotFilled = $trade->status !== 'FILLED';
                    
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

                        $this->info("  ✅ Ордер {$orderStatus} - Обновлен! (Order {$orderStatus} - Updated!)");
                        $this->line("     Количество (Quantity): {$executedQty} | Цена (Price): {$executedPrice} | Комиссия (Fee): {$fee} {$feeCurrency}");
                        $synced++;

                        // Уведомление в Telegram только если FILLED обнаружили в sync (не обновление уже FILLED от bots:run)
                        if ($isFilled && $needsUpdate && $wasNotFilled) {
                            $telegram = new TelegramService();
                            $telegram->notifyFilled($trade->side, $trade->symbol, $executedQty, $executedPrice, $fee);
                        }
                    } else {
                        $this->line("  ✓ Ордер уже синхронизирован (Order already synced - no changes needed)");
                    }

                    logger()->info('Order execution update', [
                        'trade_id' => $trade->id,
                        'order_id' => $trade->order_id,
                        'status' => $orderStatus,
                        'executed_qty' => $executedQty,
                        'price' => $executedPrice,
                    ]);

                    // 2. ВАЖНО: Закрываем позиции для ВСЕХ FILLED SELL ордеров, даже если они уже синхронизированы
                    // Это нужно для случаев, когда SELL связан с BUY, но позиция еще не закрыта
                    // Проверяем статус в БД, а не только на бирже
                    if (($isFilled || $trade->status === 'FILLED') && $trade->side === 'SELL' && $trade->bot) {
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
                                $this->info("  🔗 SELL связан с BUY #{$firstBuy->id} (SELL linked to BUY #{$firstBuy->id})");
                            }
                        }

                        // Закрываем все BUY позиции, которые были проданы этим SELL (FIFO)
                        // ВАЖНО: Закрываем только BUY позиции, которые были созданы ДО этой SELL сделки
                        $remainingSellQty = $trade->quantity;
                        $closedPositions = 0;
                        $totalPnL = 0;

                        // Получаем все открытые BUY позиции, которые были созданы ДО этой SELL сделки (FIFO)
                        $openBuys = Trade::where('trading_bot_id', $trade->bot->id)
                            ->where('side', 'BUY')
                            ->where('status', 'FILLED')
                            ->whereNull('closed_at')
                            ->where('created_at', '<=', $trade->created_at) // Только BUY, созданные до SELL
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

                                $this->info("  💰 Позиция #{$buy->id} закрыта! (Position #{$buy->id} closed!) PnL: " . number_format($pnl, 8) . " USDT");

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
                                "{$pnlEmoji} <b>ПОЗИЦИЯ(И) ЗАКРЫТА(Ы) (POSITION(S) CLOSED)</b>\n\n" .
                                "Символ (Symbol): <b>{$trade->symbol}</b>\n" .
                                "Количество продажи (Sell Quantity): <b>{$trade->quantity}</b>\n" .
                                "Цена продажи (Sell Price): <b>\${$trade->price}</b>\n" .
                                "Закрытых позиций (Closed Positions): <b>{$closedPositions}</b>\n" .
                                "Общий PnL (Total PnL): <b>" . number_format($totalPnL, 8) . " USDT</b>\n" .
                                "Время (Time): " . now()->format('Y-m-d H:i:s')
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
                    $this->warn("  ⚠️  Ордер {$orderStatus} - Помечен как FAILED (Order {$orderStatus} - Marked as FAILED)");
                    $synced++;
                } else {
                    $this->line("  ℹ️  Ордер все еще {$orderStatus} - Обновление не требуется (Order still {$orderStatus} - No update needed)");
                }

                $this->line('');

            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ❌ Ошибка (Error): " . $e->getMessage());
                $this->line('');
                logger()->error('Order sync error', [
                    'trade_id' => $trade->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // 3. ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: Закрываем позиции для всех FILLED SELL ордеров,
        // которые могли быть пропущены в основном цикле (например, если ордер уже синхронизирован)
        $this->line('');
        $this->info('Проверка незакрытых позиций (Checking unclosed positions)...');
        $this->line('');

        $filledSells = Trade::where('side', 'SELL')
            ->where('status', 'FILLED')
            ->whereNotNull('order_id')
            ->with('bot')
            ->get();

        foreach ($filledSells as $sell) {
            if (!$sell->bot) {
                continue;
            }

            // Если SELL уже имеет parent_id, проверяем конкретный BUY
            if ($sell->parent_id) {
                $buy = Trade::find($sell->parent_id);
                
                if (!$buy || $buy->side !== 'BUY' || $buy->status !== 'FILLED' || $buy->closed_at) {
                    // Позиция уже закрыта или не найдена - пропускаем без вывода
                    continue;
                }
                
                $this->info("  🔍 Проверка SELL #{$sell->id} → BUY #{$buy->id} (Checking SELL #{$sell->id} → BUY #{$buy->id})...");
                
                // Закрываем позицию, даже если количество SELL меньше количества BUY
                $buyQtySold = min($sell->quantity, $buy->quantity);
                $sellPriceRatio = $buyQtySold / $sell->quantity;
                $sellValueForBuy = $sell->price * $buyQtySold;
                $sellFeeForBuy = ($sell->fee ?? 0) * $sellPriceRatio;

                $pnl = (
                    $sellValueForBuy
                    - ($buy->price * $buyQtySold)
                    - (($buy->fee ?? 0) * ($buyQtySold / $buy->quantity))
                    - $sellFeeForBuy
                );

                $this->line("     BUY: {$buy->quantity} @ \${$buy->price} | SELL: {$sell->quantity} @ \${$sell->price}");
                $this->line("     PnL: " . number_format($pnl, 8) . " USDT");

                // ВАЖНО: Если SELL связан с BUY через parent_id, закрываем позицию независимо от количества
                // Это нужно для случаев, когда SELL был создан для закрытия конкретного BUY
                $buy->update([
                    'closed_at'    => $sell->filled_at ?? now(),
                    'realized_pnl' => $pnl,
                ]);

                $this->info("  💰 Позиция #{$buy->id} закрыта! (Position #{$buy->id} closed!) PnL: " . number_format($pnl, 8) . " USDT");

                $telegram = new TelegramService();
                $pnlEmoji = $pnl >= 0 ? '📈' : '📉';
                $telegram->sendMessage(
                    "{$pnlEmoji} <b>ПОЗИЦИЯ ЗАКРЫТА (POSITION CLOSED)</b>\n\n" .
                    "Символ (Symbol): <b>{$sell->symbol}</b>\n" .
                    "Количество продажи (Sell Quantity): <b>{$sell->quantity}</b>\n" .
                    "Цена продажи (Sell Price): <b>\${$sell->price}</b>\n" .
                    "PnL: <b>" . number_format($pnl, 8) . " USDT</b>\n" .
                    "Время (Time): " . now()->format('Y-m-d H:i:s')
                );

                logger()->info('Position closed (additional check with parent_id)', [
                    'buy_trade_id' => $buy->id,
                    'sell_trade_id' => $sell->id,
                    'pnl' => $pnl,
                    'buy_price' => $buy->price,
                    'sell_price' => $sell->price,
                    'quantity_sold' => $buyQtySold,
                    'buy_quantity' => $buy->quantity,
                    'sell_quantity' => $sell->quantity,
                ]);
                continue; // Переходим к следующему SELL
            }

            // Если нет parent_id, используем FIFO логику
            $openBuys = Trade::where('trading_bot_id', $sell->bot->id)
                ->where('side', 'BUY')
                ->where('status', 'FILLED')
                ->whereNull('closed_at')
                ->orderBy('filled_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($openBuys->isEmpty()) {
                continue; // Нет открытых позиций
            }

            // Связываем с первым открытым BUY
            $firstBuy = $openBuys->first();
            if ($firstBuy) {
                $sell->update(['parent_id' => $firstBuy->id]);
                $this->info("  🔗 SELL #{$sell->id} связан с BUY #{$firstBuy->id} (SELL #{$sell->id} linked to BUY #{$firstBuy->id})");
            }

            // Закрываем все BUY позиции, которые были проданы этим SELL (FIFO)
            $remainingSellQty = $sell->quantity;
            $closedPositions = 0;
            $totalPnL = 0;

            foreach ($openBuys as $buy) {
                if ($remainingSellQty <= 0) {
                    break; // Весь SELL уже распределен
                }

                // Определяем, сколько из этого BUY было продано
                $buyQtySold = min($remainingSellQty, $buy->quantity);
                $remainingSellQty -= $buyQtySold;

                // Рассчитываем PnL для этой части
                $sellPriceRatio = $buyQtySold / $sell->quantity;
                $sellValueForBuy = $sell->price * $buyQtySold;
                $sellFeeForBuy = ($sell->fee ?? 0) * $sellPriceRatio;

                $pnl = (
                    $sellValueForBuy
                    - ($buy->price * $buyQtySold)
                    - (($buy->fee ?? 0) * ($buyQtySold / $buy->quantity))
                    - $sellFeeForBuy
                );

                // Если продано все количество BUY, закрываем позицию
                if ($buyQtySold >= $buy->quantity) {
                    $buy->update([
                        'closed_at'    => $sell->filled_at ?? now(),
                        'realized_pnl' => $pnl,
                    ]);

                    $closedPositions++;
                    $totalPnL += $pnl;

                    $this->info("  💰 Позиция #{$buy->id} закрыта! (Position #{$buy->id} closed!) PnL: " . number_format($pnl, 8) . " USDT");

                    logger()->info('Position closed (additional check)', [
                        'buy_trade_id' => $buy->id,
                        'sell_trade_id' => $sell->id,
                        'pnl' => $pnl,
                        'buy_price' => $buy->price,
                        'sell_price' => $sell->price,
                        'quantity_sold' => $buyQtySold,
                    ]);
                }
            }

            // Отправляем уведомление в Telegram, если закрыты позиции
            if ($closedPositions > 0) {
                $telegram = new TelegramService();
                $pnlEmoji = $totalPnL >= 0 ? '📈' : '📉';
                $telegram->sendMessage(
                    "{$pnlEmoji} <b>ПОЗИЦИЯ(И) ЗАКРЫТА(Ы) (POSITION(S) CLOSED)</b>\n\n" .
                    "Символ (Symbol): <b>{$sell->symbol}</b>\n" .
                    "Количество продажи (Sell Quantity): <b>{$sell->quantity}</b>\n" .
                    "Цена продажи (Sell Price): <b>\${$sell->price}</b>\n" .
                    "Закрытых позиций (Closed Positions): <b>{$closedPositions}</b>\n" .
                    "Общий PnL (Total PnL): <b>" . number_format($totalPnL, 8) . " USDT</b>\n" .
                    "Время (Time): " . now()->format('Y-m-d H:i:s')
                );
            }
        }

        $this->line('');
        $this->info('Итоги синхронизации (Sync summary):');
        $this->line("  ✅ Синхронизировано (Synced): {$synced}");
        $this->line("  ❌ Не найдено (Not found): {$notFound}");
        $this->line("  ⚠️  Ошибок (Errors): {$errors}");
        $this->line("  ⏭️  Пропущено (Skipped): {$skipped}");
        $this->line('');
        $this->info('Синхронизация сделок завершена (Trades sync processed).');
        
        return self::SUCCESS;
    }
}
