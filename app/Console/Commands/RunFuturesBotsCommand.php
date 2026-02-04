<?php

namespace App\Console\Commands;

use App\Models\BotDecisionLog;
use App\Models\FuturesBot;
use App\Models\FuturesTrade;
use App\Services\Exchanges\OKX\OKXFuturesService;
use App\Services\TelegramService;
use App\Support\RetryHelper;
use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Strategies\RsiEmaStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Запуск фьючерсных ботов (только OKX, perpetual swap).
 * Отдельная команда от спот-ботов: php artisan futures:run
 */
class RunFuturesBotsCommand extends Command
{
    protected $signature = 'futures:run';
    protected $description = 'Run futures bots (OKX perpetual swap, low leverage)';

    public function handle(): int
    {
        if (! config('futures.enabled', true)) {
            $this->warn('Фьючерсные боты отключены (Futures bots disabled)');
            Cache::put('health_last_futures_run', now()->timestamp, now()->addDay());
            return self::SUCCESS;
        }

        $bots = FuturesBot::with('exchangeAccount')
            ->where('is_active', true)
            ->get()
            ->filter(fn ($bot) => $bot->exchangeAccount && $bot->exchangeAccount->exchange === 'okx');

        if ($bots->isEmpty()) {
            $this->warn('Активных фьючерсных ботов не найдено (No active futures bots found)');
            Cache::put('health_last_futures_run', now()->timestamp, now()->addDay());
            return self::SUCCESS;
        }

        $telegram = new TelegramService();
        $telegram->notifyFuturesRunStart($bots->count());

        // Пауза новых открытий при дневном убытке (тот же порог, что и для спота)
        $pauseThreshold = config('trading.pause_new_opens_daily_loss_usdt');
        $todayStart = now()->startOfDay();
        $dailyPnLFutures = $pauseThreshold !== null
            ? (float) FuturesTrade::whereIn('futures_bot_id', $bots->pluck('id'))
                ->whereNotNull('realized_pnl')
                ->where('closed_at', '>=', $todayStart)
                ->sum('realized_pnl')
            : 0;
        $pauseNewOpensFutures = $pauseThreshold !== null && $dailyPnLFutures <= -$pauseThreshold;

        foreach ($bots as $bot) {
            try {
            $this->line(str_repeat('-', 40));
            $this->info("Фьючерсный бот #{$bot->id}: {$bot->symbol}");
            $this->line("Плечо (Leverage): {$bot->leverage}x, Размер (Position USDT): {$bot->position_size_usdt}");

            if (! $bot->exchangeAccount) {
                $this->error('Аккаунт биржи не привязан');
                continue;
            }

            if ($bot->exchangeAccount->exchange !== 'okx') {
                $this->warn("Бот {$bot->symbol}: только OKX поддерживается, пропуск");
                continue;
            }

            try {
                $service = new OKXFuturesService($bot->exchangeAccount);
            } catch (\Throwable $e) {
                $this->error('Ошибка создания сервиса OKX: ' . $e->getMessage());
                continue;
            }

            try {
                $price = RetryHelper::retry(fn () => $service->getPrice($bot->symbol), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения цены: ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
                continue;
            }

            $this->line("Цена (Price): {$price}");

            try {
                $candles = RetryHelper::retry(fn () => $service->getCandles($bot->symbol, $bot->timeframe, 100), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения свечей: ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
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

            try {
                $hasPosition = RetryHelper::retry(fn () => $service->hasLongPosition($bot->symbol), 3, 1000);
            } catch (\Throwable $e) {
                $this->error('Ошибка получения позиций: ' . $e->getMessage());
                TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
                continue;
            }

            if ($signal === 'BUY') {
                if ($hasPosition) {
                    BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'position_already_open');
                    $this->line("Позиция уже открыта (Position already open), пропуск BUY");
                    continue;
                }
                if ($pauseNewOpensFutures) {
                    BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'pause_daily_loss');
                    $this->warn("BUY пропущен: пауза из-за дневного убытка по фьючерсам (PnL сегодня: {$dailyPnLFutures} USDT)");
                    continue;
                }
                $minMinutes = config('trading.min_minutes_between_opens');
                if ($minMinutes !== null && $minMinutes > 0) {
                    $lastOpen = FuturesTrade::where('futures_bot_id', $bot->id)->where('side', 'BUY')->whereNotNull('filled_at')->orderByDesc('filled_at')->first();
                    if ($lastOpen && $lastOpen->filled_at && $lastOpen->filled_at->diffInMinutes(now(), false) < $minMinutes) {
                        BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'cooldown_opens');
                        $this->warn("BUY пропущен: кулдаун {$minMinutes} мин (Futures)");
                        continue;
                    }
                }

                try {
                    $balance = RetryHelper::retry(fn () => $service->getBalance('USDT'), 3, 1000);
                } catch (\Throwable $e) {
                    $this->error('Ошибка получения баланса: ' . $e->getMessage());
                    TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
                    continue;
                }
                $multiplier = (float) (config('trading.position_size_multiplier', 1));
                $marginRequired = (float) $bot->position_size_usdt * $multiplier;
                if ($balance < $marginRequired && ! $bot->dry_run) {
                    BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SKIP', $price, $lastRsi, $lastEma, 'insufficient_margin');
                    $this->warn("Недостаточно маржи (Insufficient margin). Доступно: {$balance}, нужно: {$marginRequired}");
                    continue;
                }

                $ctVal = (float) (config("futures.contract_sizes.{$bot->symbol}", '0.01'));
                $contracts = $ctVal > 0 ? floor($marginRequired / ($price * $ctVal)) : 0;
                if ($contracts < 1) {
                    $contracts = 1;
                }

                if ($bot->dry_run) {
                    BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'BUY', $price, $lastRsi, $lastEma, 'dry_run');
                    $this->info("[DRY RUN] BUY {$contracts} контрактов по {$price}");
                    continue;
                }

                BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'BUY', $price, $lastRsi, $lastEma, 'strategy_buy');

                try {
                    $service->setLeverageForSymbol($bot->symbol, (int) $bot->leverage, 'cross');
                } catch (\Throwable $e) {
                    $this->warn('setLeverage: ' . $e->getMessage());
                }

                try {
                    $res = $service->placeFuturesMarketOrder($bot->symbol, 'buy', (string) $contracts, false);
                    $orderId = $res['data'][0]['ordId'] ?? null;
                    if ($orderId) {
                        FuturesTrade::create([
                            'futures_bot_id' => $bot->id,
                            'side' => 'BUY',
                            'symbol' => $bot->symbol,
                            'price' => $price,
                            'quantity' => $contracts,
                            'status' => 'PENDING',
                            'order_id' => $orderId,
                            'exchange_response' => $res,
                        ]);
                        $bot->update(['last_trade_at' => now()]);
                        $telegram->notifyFuturesTrade($bot->symbol, 'BUY', $price, $contracts);
                    }
                } catch (\Throwable $e) {
                    $this->error('Ошибка размещения ордера BUY: ' . $e->getMessage());
                }
                continue;
            }

            if ($signal === 'SELL' && $hasPosition) {
                try {
                    $posSize = RetryHelper::retry(fn () => $service->getLongPositionSize($bot->symbol), 3, 1000);
                } catch (\Throwable $e) {
                    $this->error('Ошибка получения размера позиции: ' . $e->getMessage());
                    TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
                    continue;
                }
                if ($posSize <= 0) {
                    continue;
                }

                if ($bot->dry_run) {
                    BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SELL', $price, $lastRsi, $lastEma, 'dry_run');
                    $this->info("[DRY RUN] SELL {$posSize} контрактов по {$price}");
                    continue;
                }

                BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'SELL', $price, $lastRsi, $lastEma, 'strategy_sell');
                try {
                    $sz = rtrim(rtrim(sprintf('%.8f', $posSize), '0'), '.');
                    $res = $service->placeFuturesMarketOrder($bot->symbol, 'sell', $sz, true);
                    $orderId = $res['data'][0]['ordId'] ?? null;
                    if ($orderId) {
                        FuturesTrade::create([
                            'futures_bot_id' => $bot->id,
                            'side' => 'SELL',
                            'symbol' => $bot->symbol,
                            'price' => $price,
                            'quantity' => $posSize,
                            'status' => 'PENDING',
                            'order_id' => $orderId,
                            'exchange_response' => $res,
                        ]);
                        $bot->update(['last_trade_at' => now()]);
                        $telegram->notifyFuturesTrade($bot->symbol, 'SELL', $price, $posSize);
                    }
                } catch (\Throwable $e) {
                    $this->error('Ошибка размещения ордера SELL: ' . $e->getMessage());
                }
            } else {
                // HOLD или SELL без позиции
                BotDecisionLog::log('futures', $bot->id, $bot->symbol, 'HOLD', $price, $lastRsi, $lastEma, $signal === 'SELL' ? 'no_position' : 'strategy_hold');
            }
            } catch (\Throwable $e) {
                logger()->error('futures:run bot failed', ['bot_id' => $bot->id, 'symbol' => $bot->symbol, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                TelegramService::notifyBotErrorOnce('futures', $bot->symbol, $e->getMessage(), $bot->id);
                $this->error('Фьючерсный бот ' . $bot->symbol . ' ошибка: ' . $e->getMessage());
            }
        }

        // Алерт при дневном убытке по всем фьючерсным ботам
        $dailyLossLimit = config('futures.alert_daily_loss_usdt');
        if ($dailyLossLimit !== null && $dailyLossLimit > 0) {
            $todayStart = now()->startOfDay();
            $dailyPnL = (float) FuturesTrade::whereIn('futures_bot_id', $bots->pluck('id'))
                ->whereNotNull('closed_at')
                ->whereNotNull('realized_pnl')
                ->where('closed_at', '>=', $todayStart)
                ->sum('realized_pnl');
            if ($dailyPnL <= -$dailyLossLimit) {
                $cacheKey = 'telegram_futures_daily_loss_alert_' . now()->format('Y-m-d');
                if (! Cache::has($cacheKey)) {
                    try {
                        $telegram->notifyFuturesDailyLossAlert($dailyPnL, $dailyLossLimit);
                        Cache::put($cacheKey, true, now()->endOfDay());
                    } catch (\Throwable $e) {
                        logger()->warning('Telegram futures daily loss alert failed', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        Cache::put('health_last_futures_run', now()->timestamp, now()->addDay());
        $this->info('Фьючерсные боты завершены (Futures run finished).');
        return self::SUCCESS;
    }
}
