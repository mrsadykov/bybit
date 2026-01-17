<?php

namespace App\Trading\Strategies;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\RsiIndicator;

class RsiEmaStrategy
{
    public static function decide(array $closes): string
    {
        $rsi = RsiIndicator::calculate($closes, 17);
        $ema = EmaIndicator::calculate($closes, 10);

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
