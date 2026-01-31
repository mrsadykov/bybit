<?php

return [
    // Только OKX (фьючерсы)
    'exchange' => 'okx',

    // Максимальное плечо по умолчанию для новых ботов (рекомендуется 2–3)
    'max_leverage_default' => (int) (env('FUTURES_MAX_LEVERAGE_DEFAULT', 3)),

    // Верхняя граница плеча в форме и валидации (OKX допускает до ~125x по BTC; 200 — запас)
    'max_leverage' => (int) (env('FUTURES_MAX_LEVERAGE', 125)),

    // Размер контракта по символу (OKX USDT-SWAP: 1 контракт = ctVal базовой валюты)
    // Используется для перевода position_size_usdt в количество контрактов
    'contract_sizes' => [
        'BTCUSDT' => '0.01',   // 1 contract = 0.01 BTC (~820 USDT notional → ~410 USDT margin @ 2x)
        'ETHUSDT' => '0.1',    // 1 contract = 0.1 ETH  (~300 USDT notional → ~150 USDT margin @ 2x)
        'SOLUSDT' => '1',      // 1 contract = 1 SOL  (~200 USDT notional → ~100 USDT margin @ 2x)
        'BNBUSDT' => '0.1',    // 1 contract = 0.1 BNB (~60 USDT notional  → ~30 USDT margin @ 2x)
    ],

    // Пары для выбора в форме (меньше USDT на 1 контракт — SOL, BNB, ETH; BTC — самый «тяжёлый»)
    'symbols_for_form' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'],

    // Включить фьючерсных ботов глобально (можно выключить через .env)
    'enabled' => env('FUTURES_ENABLED', true),

    // Тестнет: если true, не выставлять реальные ордера (или использовать OKX Demo)
    'dry_run_default' => env('FUTURES_DRY_RUN_DEFAULT', true),

    // Алерт в Telegram при дневном убытке по всем фьючерсным ботам (сумма realized_pnl за сегодня).
    // Например 50 — алерт, если дневной PnL <= -50 USDT. null — не слать.
    'alert_daily_loss_usdt' => env('FUTURES_ALERT_DAILY_LOSS_USDT') !== null ? (float) env('FUTURES_ALERT_DAILY_LOSS_USDT') : null,
];
