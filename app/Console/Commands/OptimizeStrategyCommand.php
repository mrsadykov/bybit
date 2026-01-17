<?php

namespace App\Console\Commands;

use App\Console\Commands\BacktestStrategyCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OptimizeStrategyCommand extends Command
{
    protected $signature = 'strategy:optimize
                            {symbol : Торговая пара (например: BTCUSDT)}
                            {--timeframe=5m : Таймфрейм (5m, 15m, 1h, 1D)}
                            {--exchange=okx : Биржа (okx или bybit)}
                            {--period=500 : Количество свечей для анализа (максимум 1000)}
                            {--rsi-min=10 : Минимальный период RSI}
                            {--rsi-max=28 : Максимальный период RSI}
                            {--rsi-step=7 : Шаг для периода RSI}
                            {--ema-min=10 : Минимальный период EMA}
                            {--ema-max=50 : Максимальный период EMA}
                            {--ema-step=10 : Шаг для периода EMA}
                            {--sl-values=3,5,7 : Значения Stop-Loss через запятую (например: 3,5,7)}
                            {--tp-values=6,10,14 : Значения Take-Profit через запятую (например: 6,10,14)}
                            {--position-size=100 : Размер позиции в USDT}
                            {--fee=0.001 : Комиссия (0.001 = 0.1%)}
                            {--sort-by=pnl : Сортировка результатов (pnl, win_rate, trades)}';

    protected $description = 'Оптимизация параметров стратегии RSI + EMA (Optimize RSI + EMA strategy parameters)';

    public function handle(): int
    {
        $symbol = $this->argument('symbol');
        $timeframe = $this->option('timeframe');
        $exchangeName = $this->option('exchange');
        $period = (int) $this->option('period');
        $rsiMin = (int) $this->option('rsi-min');
        $rsiMax = (int) $this->option('rsi-max');
        $rsiStep = (int) $this->option('rsi-step');
        $emaMin = (int) $this->option('ema-min');
        $emaMax = (int) $this->option('ema-max');
        $emaStep = (int) $this->option('ema-step');
        $slValues = array_map('floatval', explode(',', $this->option('sl-values')));
        $tpValues = array_map('floatval', explode(',', $this->option('tp-values')));
        $positionSize = (float) $this->option('position-size');
        $fee = (float) $this->option('fee');
        $sortBy = $this->option('sort-by');

        $this->info("Оптимизация параметров стратегии RSI + EMA (Optimizing RSI + EMA strategy parameters)");
        $this->line('');
        $this->line("Параметры оптимизации (Optimization Parameters):");
        $this->line("  Символ (Symbol): {$symbol}");
        $this->line("  Таймфрейм (Timeframe): {$timeframe}");
        $this->line("  Биржа (Exchange): {$exchangeName}");
        $this->line("  Период (Period): {$period} свечей");
        $this->line("  RSI диапазон: {$rsiMin} - {$rsiMax} (шаг: {$rsiStep})");
        $this->line("  EMA диапазон: {$emaMin} - {$emaMax} (шаг: {$emaStep})");
        $this->line("  Stop-Loss значения: " . implode(', ', $slValues));
        $this->line("  Take-Profit значения: " . implode(', ', $tpValues));
        $this->line('');

        // Генерируем список параметров для перебора
        $rsiPeriods = range($rsiMin, $rsiMax, $rsiStep);
        $emaPeriods = range($emaMin, $emaMax, $emaStep);

        $totalCombinations = count($rsiPeriods) * count($emaPeriods) * count($slValues) * count($tpValues);
        $this->warn("Будет протестировано комбинаций (Will test combinations): {$totalCombinations}");
        $this->warn("Это может занять некоторое время (This may take some time)...");
        $this->line('');

        if (!$this->confirm('Продолжить оптимизацию? (Continue optimization?)', true)) {
            $this->info('Оптимизация отменена (Optimization cancelled)');
            return self::SUCCESS;
        }

        // Получаем исторические данные один раз
        $this->info("Получение исторических данных (Fetching historical data)...");
        try {
            // OKX API ограничение: максимум 300 свечей за запрос
            $apiLimit = $exchangeName === 'okx' ? 300 : 1000;
            $limit = min($period + 100, $apiLimit);
            $candlesResponse = $this->fetchCandlesPublic($exchangeName, $symbol, $timeframe, $limit);
            
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

            // Обрабатываем свечи
            $candleList = [];
            foreach ($candles as $candle) {
                if ($exchangeName === 'bybit') {
                    $candleList[] = [
                        'timestamp' => (int) $candle[0],
                        'open' => (float) $candle[1],
                        'high' => (float) $candle[2],
                        'low' => (float) $candle[3],
                        'close' => (float) $candle[4],
                        'volume' => (float) $candle[5],
                    ];
                } elseif ($exchangeName === 'okx') {
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

            // Сортируем по времени (старые первые)
            usort($candleList, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
            // Берем последние N свечей (но не больше, чем есть)
            $actualPeriod = min(count($candleList), $period);
            $candleList = array_slice($candleList, -$actualPeriod);
            
            // Предупреждение, если получили меньше свечей, чем запрашивали
            if ($actualPeriod < $period && $exchangeName === 'okx') {
                $this->warn("⚠️  OKX API ограничение: получено {$actualPeriod} свечей вместо {$period} (OKX API limit: received {$actualPeriod} candles instead of {$period})");
            }

        } catch (\Throwable $e) {
            $this->error("Ошибка получения данных (Data fetch error): " . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Получено свечей (Candles fetched): " . count($candleList));
        $this->line('');

        // Создаем прогресс-бар
        $results = [];
        $current = 0;

        $this->info("Запуск оптимизации (Running optimization)...");
        $progressBar = $this->output->createProgressBar($totalCombinations);
        $progressBar->start();

        // Перебираем все комбинации
        foreach ($rsiPeriods as $rsiPeriod) {
            foreach ($emaPeriods as $emaPeriod) {
                foreach ($slValues as $stopLoss) {
                    foreach ($tpValues as $takeProfit) {
                        $current++;
                        
                        try {
                            $result = $this->runBacktest(
                                $candleList,
                                $rsiPeriod,
                                $emaPeriod,
                                $positionSize,
                                $fee,
                                $stopLoss,
                                $takeProfit
                            );

                            $results[] = [
                                'rsi_period' => $rsiPeriod,
                                'ema_period' => $emaPeriod,
                                'stop_loss' => $stopLoss,
                                'take_profit' => $takeProfit,
                                'total_pnl' => $result['total_pnl'],
                                'return_percent' => $result['return_percent'],
                                'win_rate' => $result['win_rate'],
                                'total_trades' => $result['total_trades'],
                                'winning_trades' => $result['winning_trades'],
                                'losing_trades' => $result['losing_trades'],
                            ];
                        } catch (\Throwable $e) {
                            // Игнорируем ошибки отдельных комбинаций
                        }
                        
                        $progressBar->advance();
                    }
                }
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Сортируем результаты
        // Преобразуем ключи для сортировки
        $sortKeyMap = [
            'pnl' => 'total_pnl',
            'win_rate' => 'win_rate',
            'trades' => 'total_trades',
        ];
        $actualSortKey = $sortKeyMap[$sortBy] ?? 'total_pnl';
        
        usort($results, function ($a, $b) use ($actualSortKey) {
            // Проверяем наличие ключа
            $valueA = $a[$actualSortKey] ?? 0;
            $valueB = $b[$actualSortKey] ?? 0;
            return $valueB <=> $valueA;
        });

        // Фильтруем результаты: показываем только с хотя бы 1 сделкой
        $resultsWithTrades = array_filter($results, fn($r) => $r['total_trades'] > 0);
        
        if (empty($resultsWithTrades)) {
            $this->warn("⚠️  Нет результатов с торговыми сделками (No results with trades)");
            $this->warn("   Это может означать, что на исторических данных не было торговых сигналов");
            $this->warn("   Попробуйте другие параметры или таймфреймы");
            $this->line('');
            // Показываем топ-10 даже без сделок для информации
            $this->displayTopResults(array_slice($results, 0, 10), $sortBy);
        } else {
            // Показываем статистику по количеству сделок
            $tradesCount = array_count_values(array_column($resultsWithTrades, 'total_trades'));
            $this->info("Найдено результатов с сделками (Results with trades): " . count($resultsWithTrades));
            $this->line("Распределение сделок (Trades distribution):");
            foreach ($tradesCount as $count => $freq) {
                $this->line("  {$count} сделок: {$freq} комбинаций");
            }
            $this->line('');
            
            // Показываем топ-10 результатов с сделками
            $topResults = array_slice($resultsWithTrades, 0, 10);
            $this->displayTopResults($topResults, $sortBy);
            
            // Дополнительно показываем лучшие по количеству сделок
            if ($sortBy !== 'trades') {
                $this->line('');
                $this->info("Лучшие параметры по количеству сделок (Best by number of trades):");
                usort($resultsWithTrades, fn($a, $b) => $b['total_trades'] <=> $a['total_trades']);
                $bestByTrades = array_slice($resultsWithTrades, 0, 5);
                $this->displayTopResults($bestByTrades, 'trades');
            } else {
                // Если сортируем по trades, показываем примеры с разным количеством сделок
                $maxTrades = max(array_column($resultsWithTrades, 'total_trades'));
                if ($maxTrades > 1) {
                    $this->line('');
                    $this->info("Примеры параметров с максимальным количеством сделок ({$maxTrades}):");
                    $bestByTrades = array_filter($resultsWithTrades, fn($r) => $r['total_trades'] == $maxTrades);
                    // Сортируем по PnL для выбора лучших
                    usort($bestByTrades, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);
                    $bestByTrades = array_slice($bestByTrades, 0, 5);
                    $this->displayTopResults($bestByTrades, 'pnl');
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Запуск бэктестинга с конкретными параметрами
     */
    protected function runBacktest(array $candleList, int $rsiPeriod, int $emaPeriod, float $positionSize, float $fee, ?float $stopLoss, ?float $takeProfit): array
    {
        // Используем логику из BacktestStrategyCommand
        // Здесь упрощенная версия
        $closes = array_column($candleList, 'close');
        
        // Расчет индикаторов
        $rsiValues = $this->calculateRSI($closes, $rsiPeriod);
        $emaValues = $this->calculateEMA($closes, $emaPeriod);

        // Симуляция торговли
        $balance = 1000.0;
        $position = 0.0;
        $positionCost = 0.0;
        $trades = [];
        $winningTrades = 0;
        $losingTrades = 0;

        for ($i = max($rsiPeriod, $emaPeriod); $i < count($closes); $i++) {
            $currentPrice = $closes[$i];
            $rsi = $rsiValues[$i] ?? 50;
            $ema = $emaValues[$i] ?? $currentPrice;

            // Проверка SL/TP
            if ($position > 0 && ($stopLoss || $takeProfit)) {
                $priceChange = (($currentPrice - $positionCost) / $positionCost) * 100;
                
                $shouldSell = false;
                if ($stopLoss && $priceChange <= -abs($stopLoss)) {
                    $shouldSell = true;
                }
                if ($takeProfit && $priceChange >= $takeProfit) {
                    $shouldSell = true;
                }

                if ($shouldSell) {
                    $sellValue = $position * $currentPrice * (1 - $fee);
                    $balance += $sellValue;
                    $pnl = $sellValue - ($position * $positionCost);
                    
                    if ($pnl > 0) {
                        $winningTrades++;
                    } else {
                        $losingTrades++;
                    }
                    
                    $position = 0.0;
                    $positionCost = 0.0;
                    continue;
                }
            }

            // Сигналы стратегии
            $signal = 'HOLD';
            if ($rsi < 30 && $currentPrice > $ema) {
                $signal = 'BUY';
            } elseif ($rsi > 70 && $currentPrice < $ema) {
                $signal = 'SELL';
            }

            // BUY
            if ($signal === 'BUY' && $position <= 0 && $balance >= $positionSize) {
                $buyCost = $positionSize * (1 + $fee);
                if ($balance >= $buyCost) {
                    $quantity = ($positionSize / $currentPrice) * (1 - $fee);
                    $balance -= $buyCost;
                    $position = $quantity;
                    $positionCost = $currentPrice;
                }
            }

            // SELL
            if ($signal === 'SELL' && $position > 0) {
                $sellValue = $position * $currentPrice * (1 - $fee);
                $balance += $sellValue;
                $pnl = $sellValue - ($position * $positionCost);
                
                if ($pnl > 0) {
                    $winningTrades++;
                } else {
                    $losingTrades++;
                }
                
                $position = 0.0;
                $positionCost = 0.0;
            }
        }

        // Финальная позиция
        if ($position > 0) {
            $finalValue = $position * $closes[count($closes) - 1] * (1 - $fee);
            $balance += $finalValue;
        }

        $totalTrades = $winningTrades + $losingTrades;
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;
        $totalPnL = $balance - 1000.0;
        $returnPercent = ($totalPnL / 1000.0) * 100;

        return [
            'total_pnl' => $totalPnL,
            'return_percent' => $returnPercent,
            'win_rate' => $winRate,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
        ];
    }

    /**
     * Расчет RSI
     */
    protected function calculateRSI(array $closes, int $period): array
    {
        $rsi = [];
        
        if (count($closes) <= $period) {
            // Недостаточно данных - возвращаем массив из 50
            return array_fill(0, count($closes), 50.0);
        }

        $gains = [];
        $losses = [];

        // Вычисляем изменения цен
        for ($i = 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        // Заполняем первые $period значений (недостаточно данных для расчета)
        for ($i = 0; $i < $period; $i++) {
            $rsi[] = 50.0;
        }

        // Вычисляем RSI для остальных значений
        // ВАЖНО: $gains и $losses имеют индексы от 0 до count($closes)-2
        // При индексе $i в $closes, соответствующий индекс в $gains/$losses = $i-1
        for ($i = $period; $i < count($closes); $i++) {
            // Индекс в массиве gains/losses = $i - 1
            // Берем последние $period значений
            $gainStartIdx = max(0, $i - 1 - $period + 1); // Начало среза
            $gainSlice = array_slice($gains, $gainStartIdx, $period);
            $lossSlice = array_slice($losses, $gainStartIdx, $period);
            
            $avgGain = array_sum($gainSlice) / count($gainSlice);
            $avgLoss = array_sum($lossSlice) / count($lossSlice);

            if ($avgLoss == 0) {
                $rsi[] = 100.0;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsi[] = 100 - (100 / (1 + $rs));
            }
        }

        return $rsi;
    }

    /**
     * Расчет EMA
     */
    protected function calculateEMA(array $closes, int $period): array
    {
        $ema = [];
        $multiplier = 2 / ($period + 1);

        // Первое значение - SMA
        $sma = array_sum(array_slice($closes, 0, $period)) / $period;
        for ($i = 0; $i < $period; $i++) {
            $ema[] = $sma;
        }

        // Остальные значения - EMA
        for ($i = $period; $i < count($closes); $i++) {
            $ema[] = ($closes[$i] - $ema[$i - 1]) * $multiplier + $ema[$i - 1];
        }

        return $ema;
    }

    /**
     * Показать топ-10 результатов
     */
    protected function displayTopResults(array $results, string $sortBy): void
    {
        if (empty($results)) {
            return;
        }
        
        $count = count($results);
        $title = $count <= 10 
            ? "ТОП-{$count} РЕЗУЛЬТАТОВ ОПТИМИЗАЦИИ (TOP-{$count} OPTIMIZATION RESULTS)"
            : "ТОП-10 РЕЗУЛЬТАТОВ ОПТИМИЗАЦИИ (TOP-10 OPTIMIZATION RESULTS)";
        
        $this->line(str_repeat('=', 100));
        $this->info($title);
        $this->line(str_repeat('=', 100));
        $this->line('');
        $this->line(sprintf("%-6s | %-6s | %-8s | %-8s | %-12s | %-12s | %-10s | %-8s", 'RSI', 'EMA', 'SL', 'TP', 'PnL (USDT)', 'Return (%)', 'Win Rate (%)', 'Trades'));
        $this->line(str_repeat('-', 100));

        foreach ($results as $result) {
            $pnlColor = $result['total_pnl'] >= 0 ? 'green' : 'red';
            $this->line(sprintf(
                "%-6s | %-6s | %-8s | %-8s | %-12s | %-12s | %-10s | %-8s",
                $result['rsi_period'],
                $result['ema_period'],
                $result['stop_loss'] ?? '-',
                $result['take_profit'] ?? '-',
                number_format($result['total_pnl'], 2),
                number_format($result['return_percent'], 2),
                number_format($result['win_rate'], 2),
                $result['total_trades']
            ));
        }

        $this->line('');
        $this->line(str_repeat('=', 100));
        
        if (!empty($results)) {
            $best = $results[0];
            $this->info("Лучшие параметры (Best Parameters):");
            $this->line("  RSI период (RSI Period): {$best['rsi_period']}");
            $this->line("  EMA период (EMA Period): {$best['ema_period']}");
            if ($best['stop_loss']) {
                $this->line("  Stop-Loss: {$best['stop_loss']}%");
            }
            if ($best['take_profit']) {
                $this->line("  Take-Profit: {$best['take_profit']}%");
            }
            $this->line("  Общий PnL (Total PnL): " . number_format($best['total_pnl'], 2) . " USDT");
            $this->line("  Доходность (Return): " . number_format($best['return_percent'], 2) . "%");
            $this->line("  Win Rate: " . number_format($best['win_rate'], 2) . "%");
            $this->line("  Сделок (Trades): {$best['total_trades']}");
        }
    }

    /**
     * Получить свечи через публичный API
     */
    protected function fetchCandlesPublic(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        if ($exchange === 'okx') {
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
