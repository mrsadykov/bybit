<?php

namespace App\Console\Commands;

use App\Models\BotDecisionLog;
use App\Models\BtcQuoteBot;
use App\Models\BtcQuoteTrade;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Strategies\RsiEmaStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Запуск ботов, торгующих альтами за BTC (пары SOL-BTC, ETH-BTC и т.д.).
 * Отдельная команда: php artisan btc-quote:run
 */
class RunBtcQuoteBotsCommand extends Command
{
    protected $signature = 'btc-quote:run';
    protected $description = 'Run BTC-quote bots (trade alts for BTC on OKX)';

    public function handle(): int
    {
        if (! config('btc_quote.enabled', true)) {
            $this->warn('Боты за BTC отключены (BTC-quote bots disabled)');
            Cache::put('health_last_btc_quote_run', now()->timestamp, now()->addDay());
            return self::SUCCESS;
        }

        $bots = BtcQuoteBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get()
            ->filter(fn ($bot) => $bot->exchangeAccount && $bot->exchangeAccount->exchange === 'okx');

        if ($bots->isEmpty()) {
            $this->warn('Активных ботов за BTC не найдено (No active BTC-quote bots found)');
            Cache::put('health_last_btc_quote_run', now()->timestamp, now()->addDay());
            return self::SUCCESS;
        }

        $telegram = new TelegramService();
        $telegram->notifyBtcQuoteRunStart($bots->count());

        foreach ($bots as $bot) {
            $this->line(str_repeat('-', 40));
            $this->info("Бот за BTC #{$bot->id}: {$bot->symbol}");
            $this->line("Размер позиции (Position size): {$bot->position_size_btc} BTC");

            if (! $bot->exchangeAccount) {
                $this->error('Аккаунт биржи не привязан');
                continue;
            }

            if ($bot->exchangeAccount->exchange !== 'okx') {
                $this->warn("Бот {$bot->symbol}: только OKX поддерживается, пропуск");
                continue;
            }

            try {
                $service = ExchangeServiceFactory::create($bot->exchangeAccount);
            } catch (\Throwable $e) {
                $this->error('Ошибка создания сервиса OKX: ' . $e->getMessage());
                continue;
            }

            try {
                $priceBtc = $service->getPrice($bot->symbol);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения цены: ' . $e->getMessage());
                continue;
            }

            $this->line("Цена (Price): {$priceBtc} BTC");

            try {
                $candles = $service->getCandles($bot->symbol, $bot->timeframe, 100);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения свечей: ' . $e->getMessage());
                continue;
            }

            $candleList = $candles['data'] ?? [];
            if (empty($candleList) || count($candleList) < 20) {
                $this->warn('Недостаточно данных свечей');
                continue;
            }

            $closes = array_map(fn ($c) => (float) $c[4], array_reverse($candleList));

            $rsiPeriod = $bot->rsi_period ?? 17;
            $emaPeriod = $bot->ema_period ?? 10;
            $rsiBuy = $bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 40.0;
            $rsiSell = $bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 60.0;
            $emaTolerance = (float) (config('trading.ema_tolerance_percent', 1));
            $emaToleranceDeep = config('trading.ema_tolerance_deep_percent') !== null ? (float) config('trading.ema_tolerance_deep_percent') : null;
            $rsiDeepOversold = config('trading.rsi_deep_oversold') !== null ? (float) config('trading.rsi_deep_oversold') : null;

            $signal = RsiEmaStrategy::decide($closes, $rsiPeriod, $emaPeriod, $rsiBuy, $rsiSell, false, 12, 26, 9, $emaTolerance, $emaToleranceDeep, $rsiDeepOversold);

            $lastRsi = is_array($rsi = RsiIndicator::calculate($closes, $rsiPeriod)) ? end($rsi) : $rsi;
            $lastEma = is_array($ema = EmaIndicator::calculate($closes, $emaPeriod)) ? end($ema) : $ema;
            $this->line("RSI: " . round($lastRsi, 2) . ", EMA: " . round($lastEma, 2) . ", Сигнал (Signal): {$signal}");

            $openPositionSize = $this->getOpenPositionSize($bot);
            $hasPosition = $openPositionSize > 0;

            if ($signal === 'BUY') {
                if ($hasPosition) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'position_already_open');
                    $this->line("Позиция уже открыта (Position already open), пропуск BUY");
                    continue;
                }

                $balanceBtc = $service->getBalance('BTC');
                $requiredBtc = (float) $bot->position_size_btc;
                if ($balanceBtc < $requiredBtc && ! $bot->dry_run) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'insufficient_btc');
                    $this->warn("Недостаточно BTC (Insufficient BTC). Доступно: {$balanceBtc}, нужно: {$requiredBtc}");
                    continue;
                }

                if ($bot->dry_run) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'BUY', $priceBtc, $lastRsi, $lastEma, 'dry_run');
                    $this->info("[DRY RUN] BUY на {$requiredBtc} BTC по {$priceBtc} BTC");
                    continue;
                }

                BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'BUY', $priceBtc, $lastRsi, $lastEma, 'strategy_buy');
                try {
                    $res = $service->placeMarketBuyWithQuote($bot->symbol, $requiredBtc);
                    $orderId = $res['data'][0]['ordId'] ?? null;
                    if ($orderId) {
                        $approxQty = $priceBtc > 0 ? $requiredBtc / $priceBtc : 0;
                        BtcQuoteTrade::create([
                            'btc_quote_bot_id' => $bot->id,
                            'side' => 'BUY',
                            'symbol' => $bot->symbol,
                            'price' => $priceBtc,
                            'quantity' => $approxQty,
                            'status' => 'PENDING',
                            'order_id' => $orderId,
                            'exchange_response' => $res,
                        ]);
                        $bot->update(['last_trade_at' => now()]);
                        $telegram->notifyBtcQuoteTrade($bot->symbol, 'BUY', $priceBtc, $approxQty);
                    }
                } catch (\Throwable $e) {
                    $this->error('Ошибка размещения ордера BUY: ' . $e->getMessage());
                }
                continue;
            }

            if ($signal === 'SELL' && $hasPosition) {
                $sellQty = $openPositionSize;
                if ($sellQty <= 0) {
                    continue;
                }

                if ($bot->dry_run) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SELL', $priceBtc, $lastRsi, $lastEma, 'dry_run');
                    $this->info("[DRY RUN] SELL {$sellQty} по {$priceBtc} BTC");
                    continue;
                }

                BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SELL', $priceBtc, $lastRsi, $lastEma, 'strategy_sell');
                try {
                    $sz = rtrim(rtrim(sprintf('%.8f', $sellQty), '0'), '.');
                    $res = $service->placeMarketSellBase($bot->symbol, (float) $sz);
                    $orderId = $res['data'][0]['ordId'] ?? null;
                    if ($orderId) {
                        BtcQuoteTrade::create([
                            'btc_quote_bot_id' => $bot->id,
                            'side' => 'SELL',
                            'symbol' => $bot->symbol,
                            'price' => $priceBtc,
                            'quantity' => $sellQty,
                            'status' => 'PENDING',
                            'order_id' => $orderId,
                            'exchange_response' => $res,
                            'closed_at' => now(),
                        ]);
                        $bot->update(['last_trade_at' => now()]);
                        $telegram->notifyBtcQuoteTrade($bot->symbol, 'SELL', $priceBtc, $sellQty);
                    }
                } catch (\Throwable $e) {
                    $this->error('Ошибка размещения ордера SELL: ' . $e->getMessage());
                }
            } else {
                BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'HOLD', $priceBtc, $lastRsi, $lastEma, $signal === 'SELL' ? 'no_position' : 'strategy_hold');
            }
        }

        // Алерт при дневном убытке по всем BTC-quote ботам
        $dailyLossLimitBtc = config('btc_quote.alert_daily_loss_btc');
        if ($dailyLossLimitBtc !== null && $dailyLossLimitBtc > 0) {
            $todayStart = now()->startOfDay();
            $dailyPnLBtc = (float) BtcQuoteTrade::whereIn('btc_quote_bot_id', $bots->pluck('id'))
                ->whereNotNull('closed_at')
                ->whereNotNull('realized_pnl_btc')
                ->where('closed_at', '>=', $todayStart)
                ->sum('realized_pnl_btc');
            if ($dailyPnLBtc <= -$dailyLossLimitBtc) {
                $cacheKey = 'telegram_btc_quote_daily_loss_alert_' . now()->format('Y-m-d');
                if (! Cache::has($cacheKey)) {
                    try {
                        $telegram->notifyBtcQuoteDailyLossAlert($dailyPnLBtc, $dailyLossLimitBtc);
                        Cache::put($cacheKey, true, now()->endOfDay());
                    } catch (\Throwable $e) {
                        logger()->warning('Telegram BTC-quote daily loss alert failed', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        Cache::put('health_last_btc_quote_run', now()->timestamp, now()->addDay());
        $this->info('Боты за BTC завершены (BTC-quote run finished).');
        return self::SUCCESS;
    }

    private function getOpenPositionSize(BtcQuoteBot $bot): float
    {
        $buyQty = $bot->btcQuoteTrades()->where('side', 'BUY')->sum('quantity');
        $sellQty = $bot->btcQuoteTrades()->where('side', 'SELL')->sum('quantity');
        $open = $buyQty - $sellQty;
        return max(0, (float) $open);
    }
}
