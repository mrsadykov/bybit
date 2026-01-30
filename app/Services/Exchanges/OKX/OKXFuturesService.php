<?php

namespace App\Services\Exchanges\OKX;

use App\Models\ExchangeAccount;
use RuntimeException;

/**
 * OKX Perpetual Swap (USDT-margined futures) API.
 * Символы: BTC-USDT-SWAP, ETH-USDT-SWAP, etc.
 */
class OKXFuturesService extends OKXService
{
    /**
     * Символ для SWAP: BTCUSDT -> BTC-USDT-SWAP
     */
    protected function formatSymbolSwap(string $symbol): string
    {
        $spot = $this->formatSymbol($symbol); // BTC-USDT
        if (str_ends_with($spot, '-USDT')) {
            return $spot . '-SWAP';
        }
        return $symbol;
    }

    public function getPrice(string $symbol): float
    {
        $instId = $this->formatSymbolSwap($symbol);
        $data = $this->publicRequest('/market/ticker', ['instId' => $instId]);
        if (empty($data['data'][0]['last'])) {
            throw new RuntimeException('Failed to get futures price: ' . json_encode($data));
        }
        return (float) $data['data'][0]['last'];
    }

    public function getCandles(string $symbol, string $interval, int $limit = 100): array
    {
        $instId = $this->formatSymbolSwap($symbol);
        $okxInterval = $this->formatInterval($interval);
        return $this->publicRequest('/market/candles', [
            'instId' => $instId,
            'bar' => $okxInterval,
            'limit' => (string) $limit,
        ]);
    }

    /**
     * Установить плечо для инструмента (cross margin).
     */
    public function setLeverage(string $instId, int $lever, string $mgnMode = 'cross'): array
    {
        return $this->privatePost('/account/set-leverage', [
            'instId' => $instId,
            'lever' => (string) $lever,
            'mgnMode' => $mgnMode,
        ]);
    }

    /**
     * Установить плечо по символу (BTCUSDT -> BTC-USDT-SWAP).
     */
    public function setLeverageForSymbol(string $symbol, int $lever, string $mgnMode = 'cross'): array
    {
        return $this->setLeverage($this->formatSymbolSwap($symbol), $lever, $mgnMode);
    }

    /**
     * Получить открытые позиции (SWAP).
     * @return array list of position arrays
     */
    public function getPositions(?string $instId = null): array
    {
        $params = ['instType' => 'SWAP'];
        if ($instId !== null) {
            $params['instId'] = $instId;
        }
        $data = $this->privateGet('/account/positions', $params);
        return $data['data'] ?? [];
    }

    /**
     * Есть ли открытая long-позиция по символу.
     */
    public function hasLongPosition(string $symbol): bool
    {
        $instId = $this->formatSymbolSwap($symbol);
        $positions = $this->getPositions($instId);
        foreach ($positions as $pos) {
            $posSide = $pos['posSide'] ?? '';
            $posQty = (float) ($pos['pos'] ?? 0);
            // net mode: pos > 0 = long
            if ($posSide === 'long' && $posQty > 0) {
                return true;
            }
            if (($posSide === 'net' || $posSide === '') && $posQty > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Размер long-позиции в контрактах (для закрытия).
     */
    public function getLongPositionSize(string $symbol): float
    {
        $instId = $this->formatSymbolSwap($symbol);
        $positions = $this->getPositions($instId);
        foreach ($positions as $pos) {
            $posSide = $pos['posSide'] ?? '';
            $posQty = (float) ($pos['pos'] ?? 0);
            if ($posSide === 'long' && $posQty > 0) {
                return $posQty;
            }
            if (($posSide === 'net' || $posSide === '') && $posQty > 0) {
                return $posQty;
            }
        }
        return 0.0;
    }

    /**
     * Выставить рыночный ордер по фьючерсу (открытие или закрытие).
     * @param string $symbol BTCUSDT
     * @param string $side buy | sell
     * @param string $sz размер в контрактах (OKX sz для SWAP — в контрактах)
     * @param bool $reduceOnly true для закрытия позиции
     */
    public function placeFuturesMarketOrder(string $symbol, string $side, string $sz, bool $reduceOnly = false): array
    {
        $instId = $this->formatSymbolSwap($symbol);
        $body = [
            'instId' => $instId,
            'tdMode' => 'cross',
            'side' => $side,
            'ordType' => 'market',
            'sz' => $sz,
            'posSide' => 'long', // только long для упрощения
        ];
        if ($reduceOnly) {
            $body['reduceOnly'] = true;
        }
        return $this->privatePost('/trade/order', $body);
    }

    /**
     * Доступная маржа (USDT) для фьючерсов — из баланса торгового счёта.
     */
    public function getBalance(string $coin = 'USDT'): float
    {
        $data = $this->privateGet('/account/balance', ['ccy' => $coin]);
        $details = $data['data'][0]['details'][0] ?? null;
        if (!$details) {
            return 0.0;
        }
        return (float) ($details['availBal'] ?? 0);
    }
}
