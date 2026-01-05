<?php

namespace App\Trading\Indicators;

class RsiIndicator
{
    /**
     * @param float[] $closes  Массив цен закрытия
     * @param int $period      Период RSI (обычно 14)
     */
    public static function calculate(array $closes, int $period = 14): float
    {
        if (count($closes) <= $period) {
            throw new \InvalidArgumentException('Not enough data for RSI');
        }

        $gains = 0.0;
        $losses = 0.0;

        // Первый шаг — средние gain / loss
        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];

            if ($change > 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        // Остальные свечи (Wilder smoothing)
        for ($i = $period + 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];

            $gain = $change > 0 ? $change : 0;
            $loss = $change < 0 ? abs($change) : 0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }
}
