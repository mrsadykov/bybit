<?php

namespace App\Console\Commands;

use App\Models\BotDecisionLog;
use App\Models\BtcQuoteBot;
use App\Models\BtcQuoteTrade;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\TelegramService;
use App\Support\RetryHelper;
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
        $telegram->notifyBtcQuoteRunStart($bots->count(), $bots);

        $this->syncPendingBtcQuoteTrades($bots);

        // Пауза новых открытий при дневном убытке (дневной PnL в USDT эквиваленте)
        $pauseThreshold = config('trading.pause_new_opens_daily_loss_usdt');
        $todayStart = now()->startOfDay();
        $dailyPnLBtcQuoteBtc = $pauseThreshold !== null
            ? (float) BtcQuoteTrade::whereIn('btc_quote_bot_id', $bots->pluck('id'))
                ->whereNotNull('realized_pnl_btc')
                ->where('closed_at', '>=', $todayStart)
                ->sum('realized_pnl_btc')
            : 0;
        $btcPriceUsdtForPause = 0;
        if ($pauseThreshold !== null && $dailyPnLBtcQuoteBtc !== 0.0) {
            try {
                $ticker = \Illuminate\Support\Facades\Http::timeout(5)->get('https://www.okx.com/api/v5/market/ticker?instId=BTC-USDT');
                $data = $ticker->json();
                if (($data['code'] ?? '0') === '0' && ! empty($data['data'][0]['last'])) {
                    $btcPriceUsdtForPause = (float) $data['data'][0]['last'];
                }
            } catch (\Throwable $e) {
            }
        }
        $dailyPnLBtcQuoteUsdt = $btcPriceUsdtForPause > 0 ? $dailyPnLBtcQuoteBtc * $btcPriceUsdtForPause : 0;
        $pauseNewOpensBtcQuote = $pauseThreshold !== null && $btcPriceUsdtForPause > 0 && $dailyPnLBtcQuoteUsdt <= -$pauseThreshold;

        foreach ($bots as $bot) {
            try {
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
                $priceBtc = RetryHelper::retry(fn () => $service->getPrice($bot->symbol), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения цены: ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('btc_quote', $bot->symbol, $e->getMessage(), $bot->id);
                continue;
            }

            $this->line("Цена (Price): {$priceBtc} BTC");

            try {
                $candles = RetryHelper::retry(fn () => $service->getCandles($bot->symbol, $bot->timeframe, 100), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения свечей: ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('btc_quote', $bot->symbol, $e->getMessage(), $bot->id);
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
            $conservativeRsi = config('trading.conservative_rsi', false);
            $rsiBuy = $conservativeRsi ? 35.0 : ($bot->rsi_buy_threshold !== null ? (float) $bot->rsi_buy_threshold : 40.0);
            $rsiSell = $conservativeRsi ? 65.0 : ($bot->rsi_sell_threshold !== null ? (float) $bot->rsi_sell_threshold : 60.0);
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
                    $telegram->notifySkip('BUY', 'Позиция уже открыта (Position already open)', $bot->symbol);
                    $this->line("Позиция уже открыта (Position already open), пропуск BUY");
                    continue;
                }
                if ($pauseNewOpensBtcQuote) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'pause_daily_loss');
                    $telegram->notifySkip('BUY', "Пауза: дневной убыток по BTC-quote (Pause: daily loss)", $bot->symbol);
                    $this->warn("BUY пропущен: пауза из-за дневного убытка по BTC-quote (PnL сегодня ≈ {$dailyPnLBtcQuoteUsdt} USDT)");
                    continue;
                }
                // Лимит дневного убытка по этому боту (в BTC)
                $maxDailyLossBtc = $bot->max_daily_loss_btc !== null ? (float) $bot->max_daily_loss_btc : null;
                if ($maxDailyLossBtc !== null && $maxDailyLossBtc > 0) {
                    $botDailyPnLBtc = (float) BtcQuoteTrade::where('btc_quote_bot_id', $bot->id)
                        ->whereNotNull('closed_at')->whereNotNull('realized_pnl_btc')
                        ->where('closed_at', '>=', $todayStart)
                        ->sum('realized_pnl_btc');
                    if ($botDailyPnLBtc <= -$maxDailyLossBtc) {
                        BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'bot_max_daily_loss');
                        $telegram->notifySkip('BUY', 'Дневной убыток по боту достиг лимита (Bot daily loss limit)', $bot->symbol);
                        $this->warn("BUY пропущен: дневной убыток по боту {$bot->symbol} достиг лимита ({$botDailyPnLBtc} BTC)");
                        continue;
                    }
                }
                // Серия убытков по этому боту
                $maxLosingStreak = $bot->max_losing_streak !== null ? (int) $bot->max_losing_streak : null;
                if ($maxLosingStreak !== null && $maxLosingStreak > 0) {
                    $lastTrades = BtcQuoteTrade::where('btc_quote_bot_id', $bot->id)
                        ->whereNotNull('closed_at')->whereNotNull('realized_pnl_btc')
                        ->orderByDesc('closed_at')->limit(50)->get();
                    $losingStreak = 0;
                    foreach ($lastTrades as $t) {
                        if ((float) $t->realized_pnl_btc < 0) {
                            $losingStreak++;
                        } else {
                            break;
                        }
                    }
                    if ($losingStreak >= $maxLosingStreak) {
                        BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'bot_max_losing_streak');
                        $telegram->notifySkip('BUY', "Серия убытков по боту (Losing streak: {$losingStreak})", $bot->symbol);
                        $this->warn("BUY пропущен: серия убытков по боту {$bot->symbol} ({$losingStreak})");
                        continue;
                    }
                }
                $minMinutes = config('trading.min_minutes_between_opens');
                if ($minMinutes !== null && $minMinutes > 0) {
                    $lastOpen = BtcQuoteTrade::where('btc_quote_bot_id', $bot->id)->where('side', 'BUY')->orderByDesc('created_at')->first();
                    if ($lastOpen && $lastOpen->created_at->diffInMinutes(now(), false) < $minMinutes) {
                        BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'cooldown_opens');
                        $telegram->notifySkip('BUY', "Кулдаун между открытиями (Cooldown {$minMinutes} min)", $bot->symbol);
                        $this->warn("BUY пропущен: кулдаун {$minMinutes} мин (BTC-quote)");
                        continue;
                    }
                }

                $balanceBtc = $service->getBalance('BTC');
                $multiplier = (float) (config('trading.position_size_multiplier', 1));
                $requiredBtc = (float) $bot->position_size_btc * $multiplier;
                if ($balanceBtc < $requiredBtc && ! $bot->dry_run) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'SKIP', $priceBtc, $lastRsi, $lastEma, 'insufficient_btc');
                    $telegram->notifySkip('BUY', "Недостаточно BTC (Insufficient BTC). Доступно: {$balanceBtc}, нужно: {$requiredBtc}", $bot->symbol);
                    $this->warn("Недостаточно BTC (Insufficient BTC). Доступно: {$balanceBtc}, нужно: {$requiredBtc}");
                    continue;
                }

                if ($bot->dry_run) {
                    BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'BUY', $priceBtc, $lastRsi, $lastEma, 'dry_run');
                    $telegram->notifySkip('BUY', "ТЕСТОВЫЙ РЕЖИМ (dry run). Сигнал BUY, RSI: " . round($lastRsi, 2) . ", EMA: " . round($lastEma, 2), $bot->symbol);
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
                    $telegram->notifySkip('SELL', "ТЕСТОВЫЙ РЕЖИМ (dry run). Сигнал SELL, RSI: " . round($lastRsi, 2) . ", EMA: " . round($lastEma, 2), $bot->symbol);
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
                // HOLD или SELL без позиции — уведомление как у спота: символ, цена (BTC), сигнал, RSI, EMA
                $holdReason = $signal === 'SELL' ? 'no_position' : 'strategy_hold';
                BotDecisionLog::log('btc_quote', $bot->id, $bot->symbol, 'HOLD', $priceBtc, $lastRsi, $lastEma, $holdReason);
                $telegram->notifyHold($bot->symbol, $priceBtc, $holdReason, $lastRsi, $lastEma, 'BTC');
            }
            } catch (\Throwable $e) {
                logger()->error('btc-quote:run bot failed', ['bot_id' => $bot->id, 'symbol' => $bot->symbol, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                TelegramService::notifyBotErrorOnce('btc_quote', $bot->symbol, $e->getMessage(), $bot->id);
                $this->error('Бот за BTC ' . $bot->symbol . ' ошибка: ' . $e->getMessage());
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

    /**
     * Синхронизация статусов PENDING ордеров с OKX (обновление до FILLED при заполнении).
     */
    private function syncPendingBtcQuoteTrades(\Illuminate\Support\Collection $bots): void
    {
        $botIds = $bots->pluck('id')->all();
        $pending = BtcQuoteTrade::where('status', 'PENDING')
            ->whereNotNull('order_id')
            ->whereIn('btc_quote_bot_id', $botIds)
            ->with('btcQuoteBot.exchangeAccount')
            ->get();

        foreach ($pending as $trade) {
            $bot = $trade->btcQuoteBot;
            if (! $bot || ! $bot->exchangeAccount || $bot->exchangeAccount->exchange !== 'okx') {
                continue;
            }
            try {
                $service = ExchangeServiceFactory::create($bot->exchangeAccount);
                $res = RetryHelper::retry(fn () => $service->getOrder($bot->symbol, $trade->order_id), 2, 500);
                $order = $res['data'][0] ?? null;
                if ($order && strtolower((string) ($order['state'] ?? '')) === 'filled') {
                    $trade->update([
                        'status' => 'FILLED',
                        'filled_at' => $trade->filled_at ?? now(),
                    ]);
                    $this->line("BTC-quote: ордер {$trade->order_id} отмечен как FILLED.");
                }
            } catch (\Throwable $e) {
                logger()->warning('btc-quote sync pending order failed', ['order_id' => $trade->order_id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function getOpenPositionSize(BtcQuoteBot $bot): float
    {
        $buyQty = $bot->btcQuoteTrades()->where('side', 'BUY')->sum('quantity');
        $sellQty = $bot->btcQuoteTrades()->where('side', 'SELL')->sum('quantity');
        $open = $buyQty - $sellQty;
        return max(0, (float) $open);
    }
}
