<?php

namespace App\Trading\Indicators;

class EmaIndicator
{
    public static function calculate(array $prices, int $period = 20): float
    {
        if (count($prices) < $period) {
            throw new \InvalidArgumentException('Not enough data for EMA');
        }

        $k = 2 / ($period + 1);

        // Начинаем с SMA
        $ema = array_sum(array_slice($prices, 0, $period)) / $period;

        for ($i = $period; $i < count($prices); $i++) {
            $ema = ($prices[$i] * $k) + ($ema * (1 - $k));
        }

        return round($ema, 2);
    }
}
