<?php

return [
    // Только OKX (фьючерсы)
    'exchange' => 'okx',

    // Максимальное плечо по умолчанию для новых ботов (рекомендуется 2–3)
    'max_leverage_default' => (int) (env('FUTURES_MAX_LEVERAGE_DEFAULT', 3)),

    // Размер контракта по символу (OKX USDT-SWAP: 1 контракт = ctVal базовой валюты)
    // Используется для перевода position_size_usdt в количество контрактов
    'contract_sizes' => [
        'BTCUSDT' => '0.01',   // 1 contract = 0.01 BTC
        'ETHUSDT' => '0.1',
        'SOLUSDT' => '1',
        'BNBUSDT' => '0.1',
    ],

    // Включить фьючерсных ботов глобально (можно выключить через .env)
    'enabled' => env('FUTURES_ENABLED', true),

    // Тестнет: если true, не выставлять реальные ордера (или использовать OKX Demo)
    'dry_run_default' => env('FUTURES_DRY_RUN_DEFAULT', true),
];
