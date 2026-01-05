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
        return (float) $this->bot->trades()
            ->whereIn('status', ['PENDING', 'SENT', 'FILLED'])
            ->selectRaw("
                SUM(CASE WHEN side = 'BUY' THEN quantity ELSE 0 END) -
                SUM(CASE WHEN side = 'SELL' THEN quantity ELSE 0 END)
            ")
            ->value('aggregate');
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
