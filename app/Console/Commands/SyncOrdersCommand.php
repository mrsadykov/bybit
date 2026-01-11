<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;

class SyncOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:sync';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $trades = Trade::whereIn('status', ['PENDING', 'SENT'])
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

                if (! $order) {
                    continue;
                }

                match ($order['orderStatus']) {
                    'Filled' => $trade->update([
                        'quantity'     => (float) $order['cumExecQty'],
                        'fee'          => (float) ($order['cumExecFee'] ?? 0),
                        'fee_currency' => $order['feeCurrency'] ?? null,
                        'status'       => 'FILLED',
                        'filled_at'    => now(),
                    ]),

                    'Cancelled', 'Rejected' => $trade->update([
                        'status' => 'FAILED',
                    ]),

                    default => null,
                };

            } catch (\Throwable $e) {
                logger()->error('Trade sync error', [
                    'trade_id' => $trade->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
