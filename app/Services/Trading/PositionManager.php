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
}
