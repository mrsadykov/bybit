<?php

namespace App\Console\Commands;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
use App\Trading\Strategies\RsiEmaStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BacktestStrategyCommand extends Command
{
    protected $signature = 'strategy:backtest 
                            {symbol : Торговая пара (например: BTCUSDT)}
                            {--timeframe=5m : Таймфрейм (5m, 15m, 1h, 1D)}
                            {--exchange=okx : Биржа (okx или bybit)}
                            {--period=100 : Количество свечей для анализа (максимум 1000)}
                            {--rsi-period=14 : Период RSI}
                            {--ema-period=20 : Период EMA}
                            {--rsi-buy-threshold=30 : Порог RSI для покупки (по умолчанию 30)}
                            {--rsi-sell-threshold=70 : Порог RSI для продажи (по умолчанию 70)}
                            {--position-size=100 : Размер позиции в USDT}
                            {--fee=0.001 : Комиссия (0.001 = 0.1%)}
                            {--stop-loss= : Stop-Loss процент (например: 5.0 = продать при падении на 5%)}
                            {--take-profit= : Take-Profit процент (например: 10.0 = продать при росте на 10%)}
                            {--use-macd-filter : Включить фильтр MACD (BUY при histogram ≥ 0, SELL при ≤ 0)}
                            {--json : Вывести результаты в формате JSON}';

    protected $description = 'Бэктестинг стратегии RSI + EMA на исторических данных (Backtest RSI + EMA strategy on historical data)';

    public function handle(): int
    {
        $symbol = $this->argument('symbol');
        $timeframe = $this->option('timeframe');
        $exchangeName = $this->option('exchange');
        $period = (int) $this->option('period');
        $rsiPeriod = (int) $this->option('rsi-period');
        $emaPeriod = (int) $this->option('ema-period');
        $rsiBuyThreshold = (float) $this->option('rsi-buy-threshold');
        $rsiSellThreshold = (float) $this->option('rsi-sell-threshold');
        $positionSize = (float) $this->option('position-size');
        $fee = (float) $this->option('fee');
        $stopLoss = $this->option('stop-loss') ? (float) $this->option('stop-loss') : null;
        $takeProfit = $this->option('take-profit') ? (float) $this->option('take-profit') : null;
        $useMacdFilter = $this->option('use-macd-filter');
        $jsonMode = $this->option('json');

        // В режиме JSON не выводим информационные сообщения
        if (!$jsonMode) {
            $this->info("Бэктестинг стратегии RSI + EMA (Backtesting RSI + EMA strategy)");
            $this->line('');
            $this->line("Параметры (Parameters):");
            $this->line("  Символ (Symbol): {$symbol}");
            $this->line("  Таймфрейм (Timeframe): {$timeframe}");
            $this->line("  Биржа (Exchange): {$exchangeName}");
            $this->line("  Период (Period): {$period} свечей");
            $this->line("  RSI период (RSI Period): {$rsiPeriod}");
            $this->line("  EMA период (EMA Period): {$emaPeriod}");
            $this->line("  Размер позиции (Position Size): {$positionSize} USDT");
            $this->line("  Комиссия (Fee): " . ($fee * 100) . "%");
            if ($stopLoss) {
                $this->line("  Stop-Loss: {$stopLoss}%");
            }
            if ($takeProfit) {
                $this->line("  Take-Profit: {$takeProfit}%");
            }
            if ($useMacdFilter) {
                $this->line("  Фильтр MACD (MACD filter): включён (on)");
            }
            $this->line('');
        }

        // Получаем исторические данные напрямую через публичный API
        // (для бэктестинга не нужны API ключи, только публичные данные)
        $candleList = [];
        
        if (!$jsonMode) {
            $this->info("Получение исторических данных (Fetching historical data)...");
        }
        try {
            $limit = min($period + 50, 1000); // Берем немного больше для расчета индикаторов
            $candlesResponse = $this->fetchCandlesPublic($exchangeName, $symbol, $timeframe, $limit);
            
            // Обрабатываем разные форматы ответов
            $candles = [];
            if ($exchangeName === 'bybit') {
                $candles = $candlesResponse['result']['list'] ?? [];
            } elseif ($exchangeName === 'okx') {
                $candles = $candlesResponse['data'] ?? [];
            }

            if (empty($candles)) {
                if ($jsonMode) {
                    // В режиме JSON возвращаем ошибку
                    $this->line(json_encode(['error' => 'No candles data received'], JSON_UNESCAPED_UNICODE));
                } else {
                    $this->error("Не удалось получить исторические данные (Failed to fetch historical data)");
                }
                return self::FAILURE;
            }

            // Обрабатываем свечи (разные форматы)
            foreach ($candles as $candle) {
                if ($exchangeName === 'bybit') {
                    // Bybit: [timestamp, open, high, low, close, volume]
                    $candleList[] = [
                        'timestamp' => (int) $candle[0],
                        'open' => (float) $candle[1],
                        'high' => (float) $candle[2],
                        'low' => (float) $candle[3],
                        'close' => (float) $candle[4],
                        'volume' => (float) $candle[5],
                    ];
                } elseif ($exchangeName === 'okx') {
                    // OKX: [timestamp, open, high, low, close, volume, volumeCcy, volCcyQuote, confirm]
                    $candleList[] = [
                        'timestamp' => (int) $candle[0],
                        'open' => (float) $candle[1],
                        'high' => (float) $candle[2],
                        'low' => (float) $candle[3],
                        'close' => (float) $candle[4],
                        'volume' => (float) $candle[5],
                    ];
                }
            }

            // Сортируем по времени (старые первыми)
            usort($candleList, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

            // Берем только нужное количество
            $candleList = array_slice($candleList, -$period);

            if (!$jsonMode) {
                $this->info("Получено " . count($candleList) . " свечей (Fetched " . count($candleList) . " candles)");
                $this->line('');
            }

        } catch (\Throwable $e) {
            // В режиме JSON возвращаем ошибку
            if ($jsonMode) {
                $this->line(json_encode(['error' => 'Data fetch error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error("Ошибка получения данных (Data fetch error): " . $e->getMessage());
            }
            return self::FAILURE;
        }

        $minCandles = max($rsiPeriod, $emaPeriod);
        if ($useMacdFilter) {
            $minCandles = max($minCandles, 34); // MACD 26+9-1
        }
        // Проверяем, что есть данные для бэктестинга
        if (empty($candleList) || count($candleList) < $minCandles) {
            if ($jsonMode) {
                $this->line(json_encode(['error' => 'Not enough candle data', 'candles_count' => count($candleList), 'min_required' => $minCandles], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error("Недостаточно данных для бэктестинга (Not enough data for backtest). Получено: " . count($candleList) . ", нужно минимум: {$minCandles}");
            }
            return self::FAILURE;
        }

        // Запускаем бэктестинг
        if (!$jsonMode) {
            $this->info("Запуск бэктестинга (Running backtest)...");
            $this->line('');
        }

        $results = $this->runBacktest($candleList, $rsiPeriod, $emaPeriod, $rsiBuyThreshold, $rsiSellThreshold, $positionSize, $fee, $stopLoss, $takeProfit, $useMacdFilter);

        // Добавляем параметры в результаты для анализа
        $results['parameters'] = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'exchange' => $exchangeName,
            'period' => $period,
            'rsi_period' => $rsiPeriod,
            'ema_period' => $emaPeriod,
            'position_size' => $positionSize,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'use_macd_filter' => $useMacdFilter,
        ];

        // Выводим результаты
        if ($this->option('json')) {
            // В режиме JSON выводим только JSON, без дополнительных сообщений
            // Используем $this->line() чтобы Artisan::output() мог захватить вывод
            $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } else {
            $this->displayResults($results);
        }

        return self::SUCCESS;
    }

    /**
     * Запуск бэктестинга
     */
    protected function runBacktest(array $candles, int $rsiPeriod, int $emaPeriod, float $rsiBuyThreshold, float $rsiSellThreshold, float $positionSize, float $fee, ?float $stopLoss = null, ?float $takeProfit = null, bool $useMacdFilter = false): array
    {
        $closes = array_column($candles, 'close');
        $timestamps = array_column($candles, 'timestamp');

        $balance = 1000.0; // Начальный баланс в USDT
        $position = 0.0; // Количество криптовалюты
        $positionCost = 0.0; // Средняя цена покупки

        $trades = [];
        $winningTrades = 0;
        $losingTrades = 0;
        $stopLossTrades = 0;
        $takeProfitTrades = 0;
        $currentBuyPrice = null;
        $currentBuyTimestamp = null;

        // Начинаем с индекса, достаточного для расчета индикаторов (и MACD при включённом фильтре)
        $startIndex = max($rsiPeriod, $emaPeriod, $useMacdFilter ? 34 : 0);

        for ($i = $startIndex; $i < count($closes); $i++) {
            // Получаем историю цен до текущего момента
            $historicalCloses = array_slice($closes, 0, $i + 1);
            
            // Рассчитываем индикаторы
            try {
                $rsi = RsiIndicator::calculate($historicalCloses, $rsiPeriod);
                $ema = EmaIndicator::calculate($historicalCloses, $emaPeriod);
            } catch (\Throwable $e) {
                continue; // Недостаточно данных
            }

            $currentPrice = $closes[$i];
            $currentTimestamp = $timestamps[$i];

            // Проверка Stop-Loss / Take-Profit для открытой позиции
            if ($position > 0 && $currentBuyPrice !== null) {
                $priceChange = (($currentPrice - $currentBuyPrice) / $currentBuyPrice) * 100;
                $shouldSell = false;
                $sellReason = '';

                // Проверка Stop-Loss
                if ($stopLoss && $priceChange <= -abs($stopLoss)) {
                    $shouldSell = true;
                    $sellReason = 'STOP-LOSS';
                    $stopLossTrades++;
                }

                // Проверка Take-Profit
                if ($takeProfit && $priceChange >= $takeProfit) {
                    $shouldSell = true;
                    $sellReason = 'TAKE-PROFIT';
                    $takeProfitTrades++;
                }

                if ($shouldSell) {
                    $sellValue = $position * $currentPrice;
                    $sellAmount = $sellValue * (1 - $fee);
                    $balance += $sellAmount;
                    
                    $pnl = $sellAmount - ($position * $positionCost);
                    $pnlPercent = ($pnl / ($position * $positionCost)) * 100;
                    
                    $trades[] = [
                        'buy_price' => $currentBuyPrice,
                        'sell_price' => $currentPrice,
                        'quantity' => $position,
                        'pnl' => $pnl,
                        'pnl_percent' => $pnlPercent,
                        'buy_timestamp' => $currentBuyTimestamp,
                        'sell_timestamp' => $currentTimestamp,
                        'close_reason' => $sellReason,
                    ];
                    
                    if ($pnl > 0) {
                        $winningTrades++;
                    } else {
                        $losingTrades++;
                    }
                    
                    $position = 0.0;
                    $positionCost = 0.0;
                    $currentBuyPrice = null;
                    $currentBuyTimestamp = null;
                    
                    continue; // Пропускаем проверку сигнала стратегии, т.к. уже продали по SL/TP
                }
            }

            // Применяем стратегию (с фильтром MACD при --use-macd-filter)
            if ($useMacdFilter) {
                try {
                    $signal = RsiEmaStrategy::decide($historicalCloses, $rsiPeriod, $emaPeriod, $rsiBuyThreshold, $rsiSellThreshold, true, 12, 26, 9);
                } catch (\Throwable $e) {
                    $signal = 'HOLD';
                }
            } else {
                $signal = $this->getSignal($rsi, $ema, $currentPrice, $rsiBuyThreshold, $rsiSellThreshold);
            }

            // BUY сигнал
            if ($signal === 'BUY' && $position <= 0 && $balance >= $positionSize) {
                $buyAmount = $positionSize;
                $buyCost = $buyAmount * (1 + $fee); // Комиссия при покупке
                
                if ($balance >= $buyCost) {
                    $quantity = ($buyAmount / $currentPrice) * (1 - $fee); // Комиссия уменьшает количество
                    $balance -= $buyCost;
                    $position += $quantity;
                    
                    // Обновляем среднюю цену
                    if ($position > 0) {
                        $positionCost = $currentPrice;
                    }
                    
                    $currentBuyPrice = $currentPrice;
                    $currentBuyTimestamp = $currentTimestamp;
                }
            }

            // SELL сигнал
            if ($signal === 'SELL' && $position > 0) {
                $sellValue = $position * $currentPrice;
                $sellAmount = $sellValue * (1 - $fee); // Комиссия при продаже
                $balance += $sellAmount;
                
                // Рассчитываем PnL
                $pnl = $sellAmount - ($position * $positionCost);
                $pnlPercent = ($pnl / ($position * $positionCost)) * 100;
                
                    $trades[] = [
                        'buy_price' => $currentBuyPrice,
                        'sell_price' => $currentPrice,
                        'quantity' => $position,
                        'pnl' => $pnl,
                        'pnl_percent' => $pnlPercent,
                        'buy_timestamp' => $currentBuyTimestamp,
                        'sell_timestamp' => $currentTimestamp,
                        'close_reason' => 'STRATEGY',
                    ];
                
                if ($pnl > 0) {
                    $winningTrades++;
                } else {
                    $losingTrades++;
                }
                
                $position = 0.0;
                $positionCost = 0.0;
                $currentBuyPrice = null;
                $currentBuyTimestamp = null;
            }
        }

        // Если осталась открытая позиция, считаем по текущей цене
        $finalPrice = $closes[count($closes) - 1];
        $unrealizedPnL = 0.0;
        if ($position > 0) {
            $unrealizedValue = $position * $finalPrice * (1 - $fee);
            $unrealizedPnL = $unrealizedValue - ($position * $positionCost);
            $balance += $unrealizedValue;
        }

        $totalTrades = count($trades);
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        $totalPnL = array_sum(array_column($trades, 'pnl')) + $unrealizedPnL;
        $returnPercent = (($balance - 1000.0) / 1000.0) * 100;

        return [
            'initial_balance' => 1000.0,
            'final_balance' => $balance,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'stop_loss_trades' => $stopLossTrades,
            'take_profit_trades' => $takeProfitTrades,
            'win_rate' => $winRate,
            'total_pnl' => $totalPnL,
            'return_percent' => $returnPercent,
            'trades' => $trades,
            'unrealized_pnl' => $unrealizedPnL,
            'final_position' => $position,
        ];
    }

    /**
     * Получить сигнал стратегии
     */
    protected function getSignal(float $rsi, float $ema, float $currentPrice, float $rsiBuyThreshold = 30, float $rsiSellThreshold = 70): string
    {
        // Менее строгое условие EMA: цена должна быть близко к EMA (в пределах 1%)
        // Это даст больше сигналов, чем строгое условие, но все еще учитывает тренд
        $emaTolerance = 0.01; // 1% допуск
        
        if ($rsi < $rsiBuyThreshold && $currentPrice >= $ema * (1 - $emaTolerance)) {
            return 'BUY';
        }

        if ($rsi > $rsiSellThreshold && $currentPrice <= $ema * (1 + $emaTolerance)) {
            return 'SELL';
        }

        return 'HOLD';
    }

    /**
     * Вывести результаты бэктестинга
     */
    protected function displayResults(array $results): void
    {
        $this->line(str_repeat('=', 60));
        $this->info("РЕЗУЛЬТАТЫ БЭКТЕСТИНГА (BACKTEST RESULTS)");
        $this->line(str_repeat('=', 60));
        $this->line('');

        $this->line("Начальный баланс (Initial Balance): " . number_format($results['initial_balance'], 2) . " USDT");
        $this->line("Конечный баланс (Final Balance): " . number_format($results['final_balance'], 2) . " USDT");
        $this->line("Общая прибыль/убыток (Total PnL): " . number_format($results['total_pnl'], 2) . " USDT");
        $this->line("Доходность (Return): " . number_format($results['return_percent'], 2) . "%");
        $this->line('');

        $this->line("Всего сделок (Total Trades): " . $results['total_trades']);
        $this->line("Прибыльных (Winning Trades): " . $results['winning_trades']);
        $this->line("Убыточных (Losing Trades): " . $results['losing_trades']);
        $this->line("Процент побед (Win Rate): " . number_format($results['win_rate'], 2) . "%");
        $this->line('');

        if ($results['final_position'] > 0) {
            $this->warn("Открытая позиция (Open Position): " . number_format($results['final_position'], 8));
            $this->warn("Нереализованная прибыль/убыток (Unrealized PnL): " . number_format($results['unrealized_pnl'], 2) . " USDT");
            $this->line('');
        }

        // Показываем последние 10 сделок
        if (!empty($results['trades'])) {
            $this->line("Последние 10 сделок (Last 10 Trades):");
            $this->line(str_repeat('-', 60));
            
            $lastTrades = array_slice($results['trades'], -10);
            foreach ($lastTrades as $trade) {
                $pnlSign = $trade['pnl'] >= 0 ? '+' : '';
                $pnlEmoji = $trade['pnl'] >= 0 ? '✅' : '❌';
                
                $reasonText = isset($trade['close_reason']) ? " [{$trade['close_reason']}]" : '';
                $this->line(sprintf(
                    "%s BUY: %s | SELL: %s | PnL: %s%s (%.2f%%)%s",
                    $pnlEmoji,
                    number_format($trade['buy_price'], 2),
                    number_format($trade['sell_price'], 2),
                    $pnlSign,
                    number_format($trade['pnl'], 2),
                    $trade['pnl_percent'],
                    $reasonText
                ));
            }
            $this->line('');
        }

        $this->line(str_repeat('=', 60));
    }

    /**
     * Получить свечи через публичный API (без авторизации)
     */
    protected function fetchCandlesPublic(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        if ($exchange === 'okx') {
            // OKX публичный API - используем правильный формат символа
            // OKX требует формат BTC-USDT вместо BTCUSDT
            $okxSymbol = $this->formatOkxSymbol($symbol);
            
            // OKX требует специфический формат интервалов
            $intervalMap = [
                // Минуты
                '1m' => '1m', '1' => '1m',
                '3m' => '3m', '3' => '3m',
                '5m' => '5m', '5' => '5m',
                '15m' => '15m', '15' => '15m',
                '30m' => '30m', '30' => '30m',
                // Часы (OKX требует заглавную H)
                '1h' => '1H', '1H' => '1H', '60' => '1H',
                '2h' => '2H', '2H' => '2H', '120' => '2H',
                '4h' => '4H', '4H' => '4H', '240' => '4H',
                '6h' => '6H', '6H' => '6H', '360' => '6H',
                '12h' => '12H', '12H' => '12H', '720' => '12H',
                // Дни (OKX требует заглавную D)
                '1d' => '1D', '1D' => '1D', 'D' => '1D',
                '1w' => '1W', '1W' => '1W', 'W' => '1W',
                '1M' => '1M', 'M' => '1M',
            ];
            $okxInterval = $intervalMap[strtolower($timeframe)] ?? $timeframe;
            
            $url = 'https://www.okx.com/api/v5/market/candles?' . http_build_query([
                'instId' => $okxSymbol,
                'bar' => $okxInterval,
                'limit' => (string) $limit,
            ]);
            
            $response = Http::timeout(10)->withoutVerifying()->get($url);
            
            if (!$response->successful()) {
                throw new \RuntimeException("OKX API HTTP error: Status " . $response->status() . " (Symbol: {$okxSymbol}, URL: {$url})");
            }
            
            $json = $response->json();
            
            if (!is_array($json)) {
                throw new \RuntimeException("OKX API invalid response: " . substr($response->body(), 0, 200) . " (Symbol: {$okxSymbol})");
            }
            
            if (($json['code'] ?? '0') !== '0') {
                $msg = $json['msg'] ?? 'Unknown error';
                $code = $json['code'] ?? 'Unknown';
                throw new \RuntimeException("OKX API error: {$msg} (Code: {$code}, Symbol: {$okxSymbol}, Original: {$symbol})");
            }
            
            return $json;
            
        } elseif ($exchange === 'bybit') {
            // Bybit публичный API
            $url = 'https://api.bybit.com/v5/market/kline?' . http_build_query([
                'category' => 'spot',
                'symbol' => $symbol,
                'interval' => $timeframe,
                'limit' => $limit,
            ]);
            
            $response = Http::timeout(10)->withoutVerifying()->get($url);
            $json = $response->json();
            
            if (($json['retCode'] ?? 1) !== 0) {
                throw new \RuntimeException("Bybit API error: " . ($json['retMsg'] ?? 'Unknown error'));
            }
            
            return $json;
        }
        
        throw new \RuntimeException("Unsupported exchange: {$exchange}");
    }

    /**
     * Форматировать символ для OKX API
     * Конвертирует BTCUSDT в BTC-USDT
     */
    protected function formatOkxSymbol(string $symbol): string
    {
        // Если уже есть дефис, возвращаем как есть
        if (str_contains($symbol, '-')) {
            return $symbol;
        }

        // Для BTCUSDT -> BTC-USDT
        if (str_ends_with($symbol, 'USDT')) {
            $base = substr($symbol, 0, -4);
            return $base . '-USDT';
        }

        // Для других форматов пытаемся найти паттерн (например, ETHUSDT -> ETH-USDT)
        if (strlen($symbol) > 4) {
            $quote = substr($symbol, -4);
            $base = substr($symbol, 0, -4);
            return $base . '-' . $quote;
        }

        return $symbol;
    }
}
