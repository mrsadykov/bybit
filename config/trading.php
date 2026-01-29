<?php

return [
    'real_trading' => env('REAL_TRADING', false),

    // Минимальная сумма сделки в USDT
    'min_notional_usdt' => 1,

    // защита от дурака
    'max_trades_per_bot' => 1,

    // Алерты в Telegram (опционально): при достижении лимитов отправляется уведомление
    'alert_daily_loss_usdt' => env('TRADING_ALERT_DAILY_LOSS_USDT', null), // например 10 — алерт при дневном убытке >= 10 USDT
    'alert_losing_streak_count' => env('TRADING_ALERT_LOSING_STREAK', null), // например 3 — алерт при 3+ убыточных сделках подряд
    'alert_target_profit_usdt' => env('TRADING_ALERT_TARGET_PROFIT_USDT', null), // например 50 — алерт при достижении суммарной прибыли >= 50 USDT

    'bybit' => [
        'env' => env('BYBIT_ENV', 'testnet'), // testnet | production
    ],
];
