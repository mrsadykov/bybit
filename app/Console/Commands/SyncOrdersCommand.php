<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;

class SyncOrdersCommand extends Command
{
    protected $signature = 'orders:sync';
    protected $description = 'Sync pending exchange orders';

    public function handle(): int
    {
        $this->info('Starting sync trades ...');

        $trades = Trade::whereIn('status', ['PENDING', 'SENT', 'PARTIALLY_FILLED'])
            ->whereNotNull('order_id')
            ->with('bot.exchangeAccount')
            ->get();

        foreach ($trades as $trade) {
            try {
                $bybit = new BybitService($trade->bot->exchangeAccount);

                $response = $bybit->getOrder(
                    $trade->symbol,
                    $trade->order_id
                );

                $order = $response['result']['list'][0] ?? null;

                // 2. если не нашли — идём в history
                if (! $order) {
                    $historyResponse = $bybit->getOrderHistory(
                        $trade->symbol,
                        $trade->order_id
                    );

                    $order = $historyResponse['result']['list'][0] ?? null;
                }

                if (! $order) {
                    // реально ещё не появился
                    continue;
                }

                // Обработка Filled и PartiallyFilled ордеров
                if (in_array($order['orderStatus'], ['Filled', 'PartiallyFilled'], true)) {
                    
                    $isFullyFilled = $order['orderStatus'] === 'Filled';
                    $executedQty = (float) $order['cumExecQty'];
                    $executedPrice = (float) ($order['avgPrice'] ?? $trade->price);

                    // 1. обновляем текущий трейд
                    $trade->update([
                        'price'        => $executedPrice,
                        'quantity'     => $executedQty,
                        'fee'          => (float) ($order['cumExecFee'] ?? 0),
                        'fee_currency' => $order['feeCurrency'] ?? null,
                        'status'       => $isFullyFilled ? 'FILLED' : 'PARTIALLY_FILLED',
                        'filled_at'    => $isFullyFilled ? now() : null,
                    ]);

                    logger()->info('Order execution update', [
                        'trade_id' => $trade->id,
                        'order_id' => $trade->order_id,
                        'status' => $order['orderStatus'],
                        'executed_qty' => $executedQty,
                        'price' => $executedPrice,
                    ]);

                    // 2. если это SELL и полностью исполнен — закрываем BUY и считаем PnL
                    if ($isFullyFilled && $trade->side === 'SELL' && $trade->parent_id) {

                        $buy = Trade::find($trade->parent_id);

                        if ($buy && ! $buy->closed_at) {

                            $pnl = (
                                ($trade->price * $trade->quantity)
                                - ($buy->price * $buy->quantity)
                                - ($buy->fee ?? 0)
                                - ($trade->fee ?? 0)
                            );

                            $buy->update([
                                'closed_at'    => now(),
                                'realized_pnl' => $pnl,
                            ]);

                            logger()->info('Position closed', [
                                'buy_trade_id' => $buy->id,
                                'sell_trade_id' => $trade->id,
                                'pnl' => $pnl,
                                'buy_price' => $buy->price,
                                'sell_price' => $trade->price,
                            ]);
                        }
                    }

                    continue;
                }

                if (in_array($order['orderStatus'], ['Cancelled', 'Rejected'], true)) {
                    $trade->update([
                        'status' => 'FAILED',
                    ]);
                }

            } catch (\Throwable $e) {
                logger()->error('Order sync error', [
                    'trade_id' => $trade->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info('Trades sync processed.');
        return self::SUCCESS;
    }
}
