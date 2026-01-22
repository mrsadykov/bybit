<?php

namespace App\Trading\Strategies;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;

class RsiEmaStrategy
{
    public static function decide(array $closes, ?int $rsiPeriod = null, ?int $emaPeriod = null): string
    {
        // Значения по умолчанию (для обратной совместимости)
        $rsiPeriod = $rsiPeriod ?? 17;
        $emaPeriod = $emaPeriod ?? 10;

        $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
        $ema = EmaIndicator::calculate($closes, $emaPeriod);

        $currentPrice = end($closes);

        if ($rsi < 30 && $currentPrice > $ema) {
            return 'BUY';
        }

        if ($rsi > 70 && $currentPrice < $ema) {
            return 'SELL';
        }

        return 'HOLD';
    }
}
