<?php

namespace App\Services\Trading;

use App\Models\TradingBot;

class PositionManager
{
    public function __construct(
        protected TradingBot $bot
    ) {}

    public function getNetPosition(): float
    {
        // Считаем только открытые BUY позиции (без учета SELL ордеров)
        // SELL ордера не должны учитываться в netPosition, т.к. они закрывают BUY через closed_at
        $result = $this->bot->trades()
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at') // Только незакрытые BUY позиции
            ->sum('quantity');

        return (float) ($result ?? 0);
    }

    public function canBuy(): bool
    {
        return $this->getNetPosition() <= 0;
    }

    public function canSell(): bool
    {
        return $this->getNetPosition() > 0;
    }

    public function floorQty(float $qty, float $step): float
    {
        return floor($qty / $step) * $step;
    }

    public function passesMinBuy(
        float $price,
        float $usdt,
        float $minQty,
        float $qtyStep
    ): array {
        $rawQty = $usdt / $price;
        $qty = $this->floorQty($rawQty, $qtyStep);

        if ($qty < $minQty) {
            return [false, 0];
        }

        return [true, $qty];
    }

    /**
     * Проверка минимального размера для SELL ордера
     * Возвращает [passed, minQty] где passed = true если количество >= минимума
     * 
     * @param string $symbol Торговая пара (например: BTCUSDT)
     * @param float $qty Количество для продажи
     * @return array [bool $passed, float $minQty] - прошла ли проверка и минимальное количество
     */
    public function passesMinSell(string $symbol, float $qty): array
    {
        // Минимальные размеры для популярных пар на OKX
        // Источник: OKX API /public/instruments или документация
        // ВАЖНО: Реальный минимум на OKX для BTC-USDT: 0.0001 BTC (не 0.00001!)
        $minQuantities = [
            'BTCUSDT' => 0.0001,   // 0.0001 BTC (реальный минимум OKX)
            'ETHUSDT' => 0.001,    // 0.001 ETH
            'SOLUSDT' => 0.01,     // 0.01 SOL
            'BNBUSDT' => 0.001,    // 0.001 BNB
            // Добавьте другие пары по необходимости
        ];

        $minQty = $minQuantities[$symbol] ?? 0.0001; // По умолчанию 0.0001 (как для BTC)

        if ($qty < $minQty) {
            return [false, $minQty];
        }

        return [true, $minQty];
    }
}
