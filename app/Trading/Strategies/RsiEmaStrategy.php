<?php

namespace App\Trading\Strategies;

use App\Trading\Indicators\EmaIndicator;
use App\Trading\Indicators\MacdIndicator;
use App\Trading\Indicators\RsiIndicator;

class RsiEmaStrategy
{
    /**
     * @param array $closes Цены закрытия (последний элемент — текущая свеча)
     * @param bool $useMacdFilter Если true, BUY только при histogram >= 0, SELL только при histogram <= 0
     * @param int $macdFast Период быстрой EMA для MACD (по умолчанию 12)
     * @param int $macdSlow Период медленной EMA для MACD (по умолчанию 26)
     * @param int $macdSignal Период сигнальной линии MACD (по умолчанию 9)
     */
    public static function decide(
        array $closes,
        ?int $rsiPeriod = null,
        ?int $emaPeriod = null,
        ?float $rsiBuyThreshold = null,
        ?float $rsiSellThreshold = null,
        bool $useMacdFilter = false,
        int $macdFast = 12,
        int $macdSlow = 26,
        int $macdSignal = 9
    ): string {
        // Значения по умолчанию (для обратной совместимости)
        $rsiPeriod = $rsiPeriod ?? 17;
        $emaPeriod = $emaPeriod ?? 10;

        $rsiBuyThreshold = $rsiBuyThreshold ?? 40.0;
        $rsiSellThreshold = $rsiSellThreshold ?? 60.0;

        $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
        $ema = EmaIndicator::calculate($closes, $emaPeriod);

        $currentPrice = end($closes);

        $emaTolerance = 0.01; // 1% допуск

        $macdOkBuy = true;
        $macdOkSell = true;
        if ($useMacdFilter && count($closes) >= $macdSlow + $macdSignal - 1) {
            try {
                $macd = MacdIndicator::calculate($closes, $macdFast, $macdSlow, $macdSignal);
                $macdOkBuy = $macd['histogram'] >= 0;
                $macdOkSell = $macd['histogram'] <= 0;
            } catch (\Throwable $e) {
                // Недостаточно данных для MACD — фильтр не применяем
            }
        }

        if ($rsi < $rsiBuyThreshold && $currentPrice >= $ema * (1 - $emaTolerance) && $macdOkBuy) {
            return 'BUY';
        }

        if ($rsi > $rsiSellThreshold && $currentPrice <= $ema * (1 + $emaTolerance) && $macdOkSell) {
            return 'SELL';
        }

        return 'HOLD';
    }
}
