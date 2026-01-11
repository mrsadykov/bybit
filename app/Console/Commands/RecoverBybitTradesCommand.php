<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TradingBot;
use App\Services\Exchanges\Bybit\BybitService;
use Carbon\Carbon;

class RecoverBybitTradesCommand extends Command
{
    protected $signature = 'trades:recover-bybit
                            {--symbol= : Symbol to recover (e.g. BTCUSDT)}';

    protected $description = 'Recover missing trades from Bybit and sync them into local DB';

    public function handle(): int
    {
        $this->info('Starting Bybit trades recovery...');

        $symbolFilter = $this->option('symbol');

        $bots = TradingBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get();

        if ($bots->isEmpty()) {
            $this->warn('No active bots found');
            return self::SUCCESS;
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

            $bybit = new BybitService($bot->exchangeAccount);

            try {
                // Получаем последние ордера (лимит можно увеличить при необходимости)
                $response = $bybit->getOrderHistory(
                    $bot->symbol,
                    200
                );
            } catch (\Throwable $e) {
                $this->error('Bybit error: ' . $e->getMessage());
                continue;
            }

            //dump($response);

            $orders = $response['result']['list'] ?? [];

            if (empty($orders)) {
                $this->warn('No orders returned from Bybit');
                continue;
            }

            $created = 0;

            foreach ($orders as $order) {

                $orderId = $order['orderId'] ?? null;

                if (! $orderId) {
                    continue;
                }

                $status = match ($order['orderStatus']) {
                    'Filled'    => 'FILLED',
                    'Cancelled' => 'CANCELLED',
                    'Rejected'  => 'FAILED',
                    default     => 'SENT',
                };

                $filledAt = null;

                if (! empty($order['updatedTime'])) {
                    $filledAt = Carbon::createFromTimestampMs(
                        (int) $order['updatedTime']
                    );
                }

                [$trade, $wasCreated] = $bot->trades()->updateOrCreate(
                    [
                        'order_id' => $orderId,
                    ],
                    [
                        'symbol'       => $order['symbol'],
                        'side'         => strtoupper($order['side']),
                        'price'        => (float) ($order['avgPrice'] ?: $order['price']),
                        'quantity'     => (float) $order['cumExecQty'],
                        'fee'          => (float) ($order['cumExecFee'] ?? 0),
                        'fee_currency' => $order['feeCurrency'] ?? null,
                        'status'       => $status,
                        'filled_at'    => $filledAt,
                    ]
                );

                if ($wasCreated) {
                    $created++;
                }
            }

            $this->info("Recovered {$created} trades");
        }

        $this->info('Recovery finished.');
        return self::SUCCESS;
    }
}
