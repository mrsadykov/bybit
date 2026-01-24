<?php

namespace App\Console\Commands;

use App\Models\TradingBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class BacktestAllBotsCommand extends Command
{
    protected $signature = 'strategy:backtest-all 
                            {--period=1000 : Количество свечей для анализа (рекомендуется 1000 для статистики)}
                            {--exchange=okx : Биржа (okx или bybit)}
                            {--output= : Файл для сохранения результатов (JSON)}';

    protected $description = 'Бэктестинг стратегии RSI + EMA для всех торговых ботов (Backtest RSI + EMA strategy for all trading bots)';

    public function handle(): int
    {
        $period = (int) $this->option('period');
        $exchange = $this->option('exchange');
        $outputFile = $this->option('output');

        $this->info("Бэктестинг всех торговых ботов (Backtesting all trading bots)...");
        $this->line('');

        $bots = TradingBot::all();

        if ($bots->isEmpty()) {
            $this->warn('Торговые боты не найдены (No trading bots found)');
            return self::FAILURE;
        }

        $this->info("Найдено ботов (Found bots): " . $bots->count());
        $this->line('');

        $allResults = [];

        foreach ($bots as $bot) {
            $this->line(str_repeat('=', 80));
            $this->info("Бот #{$bot->id}: {$bot->symbol}");
            $this->line(str_repeat('=', 80));

            $rsiPeriod = $bot->rsi_period ?? 17;
            $emaPeriod = $bot->ema_period ?? 10;
            $positionSize = (float) $bot->position_size;
            $stopLoss = $bot->stop_loss_percent ? (float) $bot->stop_loss_percent : null;
            $takeProfit = $bot->take_profit_percent ? (float) $bot->take_profit_percent : null;
            
            // Используем оптимальные пороги RSI для баланса между количеством сделок и качеством
            // 45/55 - хороший баланс (больше сделок, чем 40/60, но лучше качество, чем 50/50)
            // В сочетании с менее строгим условием EMA это даст хорошие результаты
            $rsiBuyThreshold = 45.0;
            $rsiSellThreshold = 55.0;

            $this->line("Параметры (Parameters):");
            $this->line("  Символ (Symbol): {$bot->symbol}");
            $this->line("  Таймфрейм (Timeframe): {$bot->timeframe}");
            $this->line("  RSI период (RSI Period): {$rsiPeriod}");
            $this->line("  EMA период (EMA Period): {$emaPeriod}");
            $this->line("  RSI Buy Threshold: {$rsiBuyThreshold}");
            $this->line("  RSI Sell Threshold: {$rsiSellThreshold}");
            $this->line("  Размер позиции (Position Size): {$positionSize} USDT");
            if ($stopLoss) {
                $this->line("  Stop-Loss: {$stopLoss}%");
            }
            if ($takeProfit) {
                $this->line("  Take-Profit: {$takeProfit}%");
            }
            $this->line('');

            try {
                Artisan::call('strategy:backtest', [
                    'symbol' => $bot->symbol,
                    '--timeframe' => $bot->timeframe,
                    '--exchange' => $exchange,
                    '--period' => $period,
                    '--rsi-period' => $rsiPeriod,
                    '--ema-period' => $emaPeriod,
                    '--rsi-buy-threshold' => $rsiBuyThreshold,
                    '--rsi-sell-threshold' => $rsiSellThreshold,
                    '--position-size' => $positionSize,
                    '--stop-loss' => $stopLoss ?: '',
                    '--take-profit' => $takeProfit ?: '',
                    '--json' => true,
                ]);

                // Получаем вывод из Artisan (теперь $this->line() используется вместо fwrite)
                $output = Artisan::output();
                
                // Извлекаем JSON из вывода - ищем первый валидный JSON с результатами (не ошибку)
                $result = null;
                
                // Убираем все переносы строк и пробелы в начале/конце для упрощения
                $cleanOutput = trim($output);
                
                // Метод 1: Ищем все JSON объекты в выводе и берем первый с результатами (не ошибку)
                // Разбиваем вывод на потенциальные JSON объекты
                $jsonObjects = [];
                $braceCount = 0;
                $currentJson = '';
                $inJson = false;
                
                for ($i = 0; $i < strlen($cleanOutput); $i++) {
                    $char = $cleanOutput[$i];
                    
                    if ($char === '{') {
                        if ($braceCount === 0) {
                            $currentJson = '{';
                            $inJson = true;
                        } else {
                            $currentJson .= $char;
                        }
                        $braceCount++;
                    } elseif ($char === '}') {
                        $currentJson .= $char;
                        $braceCount--;
                        
                        if ($braceCount === 0 && $inJson) {
                            // Нашли полный JSON объект
                            $jsonObjects[] = $currentJson;
                            $currentJson = '';
                            $inJson = false;
                        }
                    } elseif ($inJson) {
                        $currentJson .= $char;
                    }
                }
                
                // Ищем первый JSON с результатами (содержит "return_percent" и не содержит "error")
                foreach ($jsonObjects as $jsonStr) {
                    $decoded = json_decode($jsonStr, true);
                    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                        // Проверяем, что это результат, а не ошибка
                        if (isset($decoded['return_percent']) && !isset($decoded['error'])) {
                            $result = $decoded;
                            break;
                        }
                    }
                }
                
                // Метод 2: Если не нашли, пробуем найти по паттерну в строках
                if (!$result) {
                    $lines = explode("\n", $cleanOutput);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        
                        // Ищем строку с результатами (содержит "return_percent" и не содержит "error")
                        if (str_contains($line, '"return_percent"') && !str_contains($line, '"error"') && str_starts_with($line, '{') && str_ends_with($line, '}')) {
                            $decoded = json_decode($line, true);
                            if ($decoded !== null && isset($decoded['return_percent']) && !isset($decoded['error']) && json_last_error() === JSON_ERROR_NONE) {
                                $result = $decoded;
                                break;
                            }
                        }
                    }
                }
                
                if ($result && isset($result['return_percent'])) {
                    $allResults[] = [
                        'bot_id' => $bot->id,
                        'symbol' => $bot->symbol,
                        'timeframe' => $bot->timeframe,
                        'rsi_period' => $rsiPeriod,
                        'ema_period' => $emaPeriod,
                        'position_size' => $positionSize,
                        'stop_loss' => $stopLoss,
                        'take_profit' => $takeProfit,
                        'results' => $result,
                    ];

                    // Выводим краткие результаты
                    $this->displayQuickResults($result);
                } else {
                    $this->warn("Не удалось найти/распарсить JSON для {$bot->symbol}");
                    // Показываем последние 500 символов вывода для отладки
                    $lastOutput = substr($output, -500);
                    $this->line("Последние 500 символов вывода:");
                    $this->line($lastOutput);
                }

            } catch (\Throwable $e) {
                $this->error("Ошибка бэктестинга для {$bot->symbol}: " . $e->getMessage());
                $this->line("Трассировка (Trace): " . $e->getTraceAsString());
                continue;
            }

            $this->line('');
        }

        // Выводим итоговую сводку и рекомендации
        $this->displaySummary($allResults);

        // Сохраняем результаты в файл, если указан
        if ($outputFile && !empty($allResults)) {
            file_put_contents($outputFile, json_encode($allResults, JSON_PRETTY_PRINT));
            $this->info("Результаты сохранены в: {$outputFile}");
        }

        return self::SUCCESS;
    }

    protected function displayQuickResults(array $results): void
    {
        $this->line("  📊 Результаты (Results):");
        $this->line("     Доходность (Return): " . number_format($results['return_percent'] ?? 0, 2) . "%");
        $this->line("     Всего сделок (Total Trades): " . ($results['total_trades'] ?? 0));
        $this->line("     Win Rate: " . number_format($results['win_rate'] ?? 0, 2) . "%");
        $this->line("     Total PnL: " . number_format($results['total_pnl'] ?? 0, 2) . " USDT");
    }

    protected function displaySummary(array $allResults): void
    {
        $this->line(str_repeat('=', 80));
        $this->info("ИТОГОВАЯ СВОДКА И РЕКОМЕНДАЦИИ (SUMMARY AND RECOMMENDATIONS)");
        $this->line(str_repeat('=', 80));
        $this->line('');

        if (empty($allResults)) {
            $this->warn('Нет результатов для анализа (No results to analyze)');
            return;
        }

        $this->info("Протестировано ботов (Bots tested): " . count($allResults));
        $this->line('');

        // Сортируем по доходности
        usort($allResults, function($a, $b) {
            $returnA = $a['results']['return_percent'] ?? 0;
            $returnB = $b['results']['return_percent'] ?? 0;
            return $returnB <=> $returnA;
        });

        $this->info("🏆 ТОП-3 ЛУЧШИХ РЕЗУЛЬТАТА (TOP-3 BEST RESULTS):");
        $this->line('');

        foreach (array_slice($allResults, 0, min(3, count($allResults))) as $index => $result) {
            $this->line(($index + 1) . ". {$result['symbol']} ({$result['timeframe']})");
            $this->line("   Доходность (Return): " . number_format($result['results']['return_percent'] ?? 0, 2) . "%");
            $this->line("   Win Rate: " . number_format($result['results']['win_rate'] ?? 0, 2) . "%");
            $this->line("   Total PnL: " . number_format($result['results']['total_pnl'] ?? 0, 2) . " USDT");
            $this->line("   Параметры: RSI={$result['rsi_period']}, EMA={$result['ema_period']}");
            if ($result['stop_loss']) {
                $this->line("   SL={$result['stop_loss']}%, TP={$result['take_profit']}%");
            }
            $this->line('');
        }

        // Рекомендации
        $this->info("💡 РЕКОМЕНДАЦИИ (RECOMMENDATIONS):");
        $this->line('');

        $returns = array_filter(array_column(array_column($allResults, 'results'), 'return_percent'));
        $winRates = array_filter(array_column(array_column($allResults, 'results'), 'win_rate'));

        if (!empty($returns)) {
            $avgReturn = array_sum($returns) / count($returns);
            $maxReturn = max($returns);
            $minReturn = min($returns);

            $this->line("Средняя доходность (Average Return): " . number_format($avgReturn, 2) . "%");
            $this->line("Максимальная доходность (Max Return): " . number_format($maxReturn, 2) . "%");
            $this->line("Минимальная доходность (Min Return): " . number_format($minReturn, 2) . "%");
            $this->line('');

            if ($avgReturn > 5) {
                $this->info("✅ Стратегия показывает хорошую доходность (>5%)");
            } elseif ($avgReturn > 0) {
                $this->warn("⚠️ Стратегия показывает низкую доходность (0-5%)");
            } else {
                $this->error("❌ Стратегия показывает отрицательную доходность");
            }
        }

        if (!empty($winRates)) {
            $avgWinRate = array_sum($winRates) / count($winRates);
            $this->line("Средний Win Rate (Average Win Rate): " . number_format($avgWinRate, 2) . "%");
            $this->line('');

            if ($avgWinRate > 55) {
                $this->info("✅ Win Rate выше 55% - стратегия работает хорошо");
            } elseif ($avgWinRate > 50) {
                $this->warn("⚠️ Win Rate 50-55% - можно улучшить");
            } else {
                $this->error("❌ Win Rate ниже 50% - нужно оптимизировать параметры");
            }
        }

        // Рекомендации по параметрам
        $this->line('');
        $this->info("🔧 РЕКОМЕНДАЦИИ ПО ОПТИМИЗАЦИИ:");
        $this->line('');

        $bestResult = $allResults[0] ?? null;
        if ($bestResult && ($bestResult['results']['return_percent'] ?? 0) > 0) {
            $this->line("Лучшие параметры (Best Parameters):");
            $this->line("  RSI Period: {$bestResult['rsi_period']}");
            $this->line("  EMA Period: {$bestResult['ema_period']}");
            if ($bestResult['stop_loss']) {
                $this->line("  Stop-Loss: {$bestResult['stop_loss']}%");
                $this->line("  Take-Profit: {$bestResult['take_profit']}%");
            } else {
                $this->line("  Рекомендуется добавить Stop-Loss и Take-Profit для защиты капитала");
            }
        }

        $this->line('');
        $this->info("📝 Следующие шаги:");
        $this->line("1. Проанализируйте результаты для каждой пары");
        $this->line("2. Примените лучшие параметры к ботам");
        $this->line("3. Добавьте Stop-Loss и Take-Profit для защиты капитала");
        $this->line("4. Запустите реальную торговлю с оптимизированными параметрами");
    }
}
