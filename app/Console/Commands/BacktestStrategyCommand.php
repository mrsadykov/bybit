<?php

namespace App\Console\Commands;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;
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
                            {--position-size=100 : Размер позиции в USDT}
                            {--fee=0.001 : Комиссия (0.001 = 0.1%)}
                            {--stop-loss= : Stop-Loss процент (например: 5.0 = продать при падении на 5%)}
                            {--take-profit= : Take-Profit процент (например: 10.0 = продать при росте на 10%)}';

    protected $description = 'Бэктестинг стратегии RSI + EMA на исторических данных (Backtest RSI + EMA strategy on historical data)';

    public function handle(): int
    {
        $symbol = $this->argument('symbol');
        $timeframe = $this->option('timeframe');
        $exchangeName = $this->option('exchange');
        $period = (int) $this->option('period');
        $rsiPeriod = (int) $this->option('rsi-period');
        $emaPeriod = (int) $this->option('ema-period');
        $positionSize = (float) $this->option('position-size');
        $fee = (float) $this->option('fee');
        $stopLoss = $this->option('stop-loss') ? (float) $this->option('stop-loss') : null;
        $takeProfit = $this->option('take-profit') ? (float) $this->option('take-profit') : null;

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
        $this->line('');

        // Получаем исторические данные напрямую через публичный API
        // (для бэктестинга не нужны API ключи, только публичные данные)
        $this->info("Получение исторических данных (Fetching historical data)...");
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
                $this->error("Не удалось получить исторические данные (Failed to fetch historical data)");
                return self::FAILURE;
            }

            // Обрабатываем свечи (разные форматы)
            $candleList = [];
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

            $this->info("Получено " . count($candleList) . " свечей (Fetched " . count($candleList) . " candles)");
            $this->line('');

        } catch (\Throwable $e) {
            $this->error("Ошибка получения данных (Data fetch error): " . $e->getMessage());
            return self::FAILURE;
        }

        // Запускаем бэктестинг
        $this->info("Запуск бэктестинга (Running backtest)...");
        $this->line('');

        $results = $this->runBacktest($candleList, $rsiPeriod, $emaPeriod, $positionSize, $fee, $stopLoss, $takeProfit);

        // Выводим результаты
        $this->displayResults($results);

        return self::SUCCESS;
    }

    /**
     * Запуск бэктестинга
     */
    protected function runBacktest(array $candles, int $rsiPeriod, int $emaPeriod, float $positionSize, float $fee, ?float $stopLoss = null, ?float $takeProfit = null): array
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

        // Начинаем с индекса, достаточного для расчета индикаторов
        $startIndex = max($rsiPeriod, $emaPeriod);

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

            // Применяем стратегию
            $signal = $this->getSignal($rsi, $ema, $currentPrice);

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
    protected function getSignal(float $rsi, float $ema, float $currentPrice): string
    {
        if ($rsi < 30 && $currentPrice > $ema) {
            return 'BUY';
        }

        if ($rsi > 70 && $currentPrice < $ema) {
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
            // OKX публичный API
            $okxSymbol = str_ends_with($symbol, 'USDT') 
                ? substr($symbol, 0, -4) . '-USDT' 
                : $symbol;
            
            $intervalMap = [
                '1' => '1m', '3' => '3m', '5' => '5m', '15' => '15m', '30' => '30m',
                '60' => '1H', '120' => '2H', '240' => '4H', '360' => '6H', '720' => '12H',
                'D' => '1D', 'W' => '1W', 'M' => '1M',
                '1m' => '1m', '5m' => '5m', '15m' => '15m', '1h' => '1H', '1H' => '1H',
                '1d' => '1D', '1D' => '1D',
            ];
            $okxInterval = $intervalMap[$timeframe] ?? $timeframe;
            
            $url = 'https://www.okx.com/api/v5/market/candles?' . http_build_query([
                'instId' => $okxSymbol,
                'bar' => $okxInterval,
                'limit' => (string) $limit,
            ]);
            
            $response = Http::timeout(10)->withoutVerifying()->get($url);
            $json = $response->json();
            
            if (($json['code'] ?? '0') !== '0') {
                throw new \RuntimeException("OKX API error: " . ($json['msg'] ?? 'Unknown error'));
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
}
