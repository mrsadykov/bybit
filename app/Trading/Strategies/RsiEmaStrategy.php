<?php

namespace App\Trading\Strategies;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;

class RsiEmaStrategy
{
    public static function decide(array $closes, ?int $rsiPeriod = null, ?int $emaPeriod = null, ?float $rsiBuyThreshold = null, ?float $rsiSellThreshold = null): string
    {
        // Значения по умолчанию (для обратной совместимости)
        $rsiPeriod = $rsiPeriod ?? 17;
        $emaPeriod = $emaPeriod ?? 10;
        
        // Консервативные пороги по умолчанию для реальной торговли
        // 40/60 - более строгие условия, меньше сделок, но более качественные сигналы
        // Меньше комиссий, меньше ложных сигналов, более стабильная торговля
        // Для бэктестинга можно использовать 45/55 для получения большего количества данных
        $rsiBuyThreshold = $rsiBuyThreshold ?? 40.0;
        $rsiSellThreshold = $rsiSellThreshold ?? 60.0;

        $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
        $ema = EmaIndicator::calculate($closes, $emaPeriod);

        $currentPrice = end($closes);

        // Менее строгое условие EMA: цена должна быть близко к EMA (в пределах 1%)
        // Это даст больше сигналов, чем строгое условие, но все еще учитывает тренд
        // Такое же условие используется в бэктестинге для согласованности
        $emaTolerance = 0.01; // 1% допуск
        
        if ($rsi < $rsiBuyThreshold && $currentPrice >= $ema * (1 - $emaTolerance)) {
            return 'BUY';
        }

        if ($rsi > $rsiSellThreshold && $currentPrice <= $ema * (1 + $emaTolerance)) {
            return 'SELL';
        }

        return 'HOLD';
    }
}
