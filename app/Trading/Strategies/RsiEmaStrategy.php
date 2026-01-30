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
     * @param float $emaTolerancePercent Допуск цены относительно EMA для BUY/SELL в % (1 = 1%). BUY: цена >= EMA*(1 - tolerance/100)
     * @param float|null $emaToleranceDeepPercent При глубокой перепроданности (RSI < rsiDeepOversold) допуск для BUY в % (например 3). null = не использовать
     * @param float|null $rsiDeepOversold Порог RSI для «глубокой перепроданности» (например 25). null = отключено
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
        int $macdSignal = 9,
        float $emaTolerancePercent = 1.0,
        ?float $emaToleranceDeepPercent = null,
        ?float $rsiDeepOversold = null
    ): string {
        // Значения по умолчанию (для обратной совместимости)
        $rsiPeriod = $rsiPeriod ?? 17;
        $emaPeriod = $emaPeriod ?? 10;

        $rsiBuyThreshold = $rsiBuyThreshold ?? 40.0;
        $rsiSellThreshold = $rsiSellThreshold ?? 60.0;

        $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
        $ema = EmaIndicator::calculate($closes, $emaPeriod);

        $currentPrice = end($closes);

        // Допуск для BUY: цена может быть ниже EMA на tolerance%
        $buyTolerance = $emaTolerancePercent / 100.0;
        if ($rsiDeepOversold !== null && $emaToleranceDeepPercent !== null && $rsi < $rsiDeepOversold) {
            $buyTolerance = $emaToleranceDeepPercent / 100.0;
        }
        $sellTolerance = $emaTolerancePercent / 100.0;

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

        if ($rsi < $rsiBuyThreshold && $currentPrice >= $ema * (1 - $buyTolerance) && $macdOkBuy) {
            return 'BUY';
        }

        if ($rsi > $rsiSellThreshold && $currentPrice <= $ema * (1 + $sellTolerance) && $macdOkSell) {
            return 'SELL';
        }

        return 'HOLD';
    }
}
