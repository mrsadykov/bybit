<?php

namespace App\Trading\Indicators;

/**
 * MACD (Moving Average Convergence Divergence).
 * MACD line = EMA(fast) - EMA(slow), Signal = EMA(MACD line), Histogram = MACD - Signal.
 */
class MacdIndicator
{
    public static function calculate(
        array $closes,
        int $fastPeriod = 12,
        int $slowPeriod = 26,
        int $signalPeriod = 9
    ): array {
        $n = count($closes);
        $minLength = $slowPeriod + $signalPeriod - 1;
        if ($n < $minLength) {
            throw new \InvalidArgumentException("Not enough data for MACD: need at least {$minLength}, got {$n}");
        }

        $fastEma = self::emaSeries($closes, $fastPeriod);
        $slowEma = self::emaSeries($closes, $slowPeriod);

        $macdLine = [];
        for ($i = $slowPeriod - 1; $i < $n; $i++) {
            $macdLine[] = $fastEma[$i] - $slowEma[$i];
        }

        $signalLine = self::emaSeries($macdLine, $signalPeriod);
        $lastMacd = end($macdLine);
        $lastSignal = end($signalLine);
        $histogram = $lastMacd - $lastSignal;

        return [
            'macd'      => round($lastMacd, 8),
            'signal'    => round($lastSignal, 8),
            'histogram' => round($histogram, 8),
        ];
    }

    /**
     * @param array $values
     * @param int $period
     * @return array EMA for each index from (period-1) to end
     */
    private static function emaSeries(array $values, int $period): array
    {
        $n = count($values);
        $k = 2 / ($period + 1);
        $sma = array_sum(array_slice($values, 0, $period)) / $period;
        $result = array_fill(0, $period - 1, 0);
        $result[] = $sma;
        $ema = $sma;
        for ($i = $period; $i < $n; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
            $result[] = $ema;
        }
        return $result;
    }
}
