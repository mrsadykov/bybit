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
        
        // Более мягкие пороги по умолчанию для большего количества торговых возможностей
        // 40/60 вместо 30/70 (более реалистичные значения)
        $rsiBuyThreshold = $rsiBuyThreshold ?? 40.0;
        $rsiSellThreshold = $rsiSellThreshold ?? 60.0;

        $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
        $ema = EmaIndicator::calculate($closes, $emaPeriod);

        $currentPrice = end($closes);

        if ($rsi < $rsiBuyThreshold && $currentPrice > $ema) {
            return 'BUY';
        }

        if ($rsi > $rsiSellThreshold && $currentPrice < $ema) {
            return 'SELL';
        }

        return 'HOLD';
    }
}
