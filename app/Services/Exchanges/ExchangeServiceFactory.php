<?php

namespace App\Services\Exchanges;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\Bybit\BybitService;
use App\Services\Exchanges\OKX\OKXService;
use RuntimeException;

class ExchangeServiceFactory
{
    /**
     * Создает сервис для работы с биржей на основе аккаунта
     */
    public static function create(ExchangeAccount $account): BybitService|OKXService
    {
        return match ($account->exchange) {
            'bybit' => new BybitService($account),
            'okx' => new OKXService($account),
            default => throw new RuntimeException("Unsupported exchange: {$account->exchange}"),
        };
    }
}
